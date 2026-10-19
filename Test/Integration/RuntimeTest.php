<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Integration;

use LoyaltyEngage\LoyaltyShop\Cron\DeliverEvents;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use LoyaltyEngage\LoyaltyShop\Model\MutationJournal;
use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Model\Queue\PurchaseConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Service\ApiException;
use LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Local Magento smoke tests. Every database mutation is rolled back; no external API is called. */
class RuntimeTest extends TestCase
{
    private static $om;
    private ResourceConnection $resource;
    private $db;
    private EventOutbox $outbox;
    private int $storeId;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 6) . '/app/bootstrap.php';
        self::$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        try {
            self::$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
        }
    }

    protected function setUp(): void
    {
        $this->resource = self::$om->get(ResourceConnection::class);
        $this->db = $this->resource->getConnection();
        $this->db->beginTransaction();
        $this->outbox = new EventOutbox($this->resource);
        $this->storeId = (int) $this->db->fetchOne($this->db->select()->from($this->resource->getTableName('store'), ['store_id'])
            ->where('store_id > ?', 0)->order('store_id ASC')->limit(1));
        self::assertGreaterThan(0, $this->storeId);
        // Keep existing pending events out of the smoke worker's batch, inside this rollback transaction.
        $this->db->update($this->outbox->table(), ['available_at' => '2099-01-01 00:00:00'], ['status = ?' => 'pending']);
        $this->db->update($this->resource->getTableName('loyaltyshop_mutation'),
            ['available_at' => '2099-01-01 00:00:00'], ['status IN (?)' => ['received', 'started', 'uncertain']]);
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    public function testSetupPatchesLeaveDeploymentConfigurationUnchanged(): void
    {
        $path = BP . '/app/etc/env.php';
        $before = file_get_contents($path);
        $patch = new \LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\ConfigureCronConsumers();
        self::assertSame($patch, $patch->apply());
        self::assertSame($patch, $patch->apply());
        $upgrade = new \LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\UpgradeEventDelivery($this->resource);
        $upgrade->apply();
        self::assertSame($before, file_get_contents($path));
        self::assertSame([], $upgrade::getDependencies());
        self::assertNotFalse($this->db->fetchOne($this->db->select()
            ->from($this->resource->getTableName('core_config_data'), ['value'])
            ->where('path = ?', 'loyalty/export/tracking_started_at')));
    }

    public function testQuantityReductionRemovesOnlyRewardAndPersistsOneRemoval(): void
    {
        $helper = self::$om->get(Data::class);
        // In-memory scoped fixture: no tenant configuration is persisted or cached.
        $property = new \ReflectionProperty(\Magento\Framework\App\Helper\AbstractHelper::class, 'scopeConfig');
        $property->setAccessible(true);
        $original = $property->getValue($helper);
        $config = self::$om->create(\Magento\Framework\App\MutableScopeConfig::class);
        $config->setValue('loyalty/general/module_enable', 1, 'store', $this->storeId);
        $config->setValue('loyalty/general/minimum_order_value', 50, 'store', $this->storeId);
        $property->setValue($helper, $config);
        try {
            $quote = self::$om->create(\Magento\Quote\Model\Quote::class)->setStoreId($this->storeId)
                ->setCustomerEmail('minimum-fixture@example.invalid')->setCustomerIsGuest(true)->setIsActive(true);
            $quote->getBillingAddress();
            $quote->getShippingAddress();
            $product = self::$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)
                ->get('24-MB01', false, $this->storeId);
            $paid = $quote->addProduct(clone $product, new \Magento\Framework\DataObject(['qty' => 3]));
            self::assertIsNotString($paid);
            $paid->setCustomPrice(20)->setOriginalCustomPrice(20);
            $rewardProduct = clone $product;
            $rewardProduct->addCustomOption('loyalty_locked_qty', '1');
            $reward = $quote->addProduct($rewardProduct, new \Magento\Framework\DataObject(['qty' => 1]));
            self::assertIsNotString($reward);
            $reward->setCustomPrice(0)->setOriginalCustomPrice(0)->setData('loyalty_locked_qty', 1);
            $reward->addOption(['code' => 'loyalty_locked_qty', 'value' => '1']);
            $quotes = self::$om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            self::assertCount(2, $quote->getAllVisibleItems(), json_encode([
                'subtotal' => $quote->getSubtotal(), 'paid_qty' => $paid->getQty(), 'paid_row' => $paid->getRowTotalInclTax(),
                'paid_marked' => $helper->isLoyaltyProduct($paid, true), 'reward_deleted' => $reward->isDeleted(),
            ]));
            $quotes->save($quote);
            self::assertCount(2, $quote->getAllVisibleItems());
            $rewardId = (int) $reward->getId();
            $paidId = (int) $paid->getId();
            $quote = $quotes->get((int) $quote->getId());
            $paid = $quote->getItemById($paidId);
            $reward = $quote->getItemById($rewardId);
            $paid->setQty(1);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $quotes->save($quote);
            self::assertCount(1, $quote->getAllVisibleItems());
            self::assertFalse($paid->isDeleted());
            self::assertTrue($reward->isDeleted());
            $event = $this->outbox->find('loyaltyshop.free_product_remove_event',
                ['store_id' => $this->storeId, 'removal_id' => $rewardId]);
            self::assertNotNull($event);
            self::assertSame('pending', $event['status']);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $quotes->save($quote);
            self::assertSame(1, (int) $this->db->fetchOne($this->db->select()->from($this->outbox->table(), ['COUNT(*)'])
                ->where('event_key = ?', $event['event_key'])));
            self::assertLessThan(50, (float) $quote->getSubtotal());
        } finally {
            $property->setValue($helper, $original);
        }
    }

    /** @dataProvider completedCycleProvider */
    public function testOnlyExactCompletedReservationCyclesStopBlockingRecovery(string $removalStatus, string $journalStatus,
        bool $validKey, bool $expected): void
    {
        $customerId = (int) $this->db->fetchOne('SELECT entity_id FROM customer_entity LIMIT 1');
        $quote = self::$om->create(\Magento\Quote\Model\Quote::class)->setStoreId($this->storeId)
            ->setCustomerId($customerId)->setCustomerEmail('cycle-' . bin2hex(random_bytes(4)) . '@example.invalid')
            ->setIsActive(true);
        self::$om->get(\Magento\Quote\Model\ResourceModel\Quote::class)->save($quote);
        $sku = 'CYCLE-' . bin2hex(random_bytes(4));
        $oldKey = hash('sha256', $this->storeId . ':' . $quote->getId() . ':product:' . $sku . ':0');
        if (!$validKey) {
            $oldKey = hash('sha256', 'unproven-' . $oldKey);
        }
        $table = $this->resource->getTableName('loyaltyshop_mutation');
        $this->db->insert($table, ['operation_key' => $oldKey, 'store_id' => $this->storeId,
            'customer_id' => $customerId, 'quote_id' => $quote->getId(), 'operation' => 'product', 'sku' => $sku,
            'status' => $journalStatus]);
        $payload = ['store_id' => $this->storeId, 'quote_id' => $quote->getId(), 'sku' => $sku,
            'email' => $quote->getCustomerEmail(), 'quantity' => 1, 'removal_id' => random_int(1000000000, 2000000000)];
        $this->outbox->publish('loyaltyshop.free_product_remove_event', json_encode($payload));
        $removal = $this->outbox->find('loyaltyshop.free_product_remove_event', $payload);
        $this->db->update($this->outbox->table(), ['status' => $removalStatus], ['entity_id = ?' => $removal['entity_id']]);
        $currentKey = hash('sha256', $this->storeId . ':' . $quote->getId() . ':product:' . $sku . ':' . $removal['entity_id']);
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::never())->method('post');
        $api->expects(self::never())->method('delete');
        $api->expects($expected ? self::once() : self::never())->method('get')
            ->willReturn(['products' => [['sku' => $sku, 'quantity' => 1, 'coinPrice' => 800]]]);
        $helper = $this->createMock(Data::class);
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $helper->method('hashEmail')->willReturn('fixture');
        $result = (new \LoyaltyEngage\LoyaltyShop\Model\PhysicalCartReconciler($this->resource, $api, $helper))
            ->confirm($quote, $sku, $currentKey);
        self::assertSame($expected, $result !== null);
        self::assertSame($expected ? 'released' : $journalStatus,
            self::$om->get(MutationJournal::class)->get($oldKey)['status']);
    }

    public static function completedCycleProvider(): array
    {
        return [
            'confirmed removal retires applied cycle' => ['sent', 'applied', true, true],
            'pending removal stays protected' => ['pending', 'applied', true, false],
            'failed removal stays protected' => ['failed', 'applied', true, false],
            'unknown key stays protected' => ['sent', 'applied', false, false],
            'uncertain mutation stays protected' => ['sent', 'uncertain', true, false],
            'received mutation stays protected' => ['sent', 'received', true, false],
        ];
    }

    public function testNativeCreditmemoConversionPreservesHistoricalTaxInclusiveUnitPrice(): void
    {
        $orderItem = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class)->create()
            ->setSku('TAX-FIXTURE')->setPrice(25)->setBasePrice(20)
            ->setPriceInclTax(30.25)->setBasePriceInclTax(24.20)->setQtyOrdered(4);
        $memoItem = self::$om->get(\Magento\Sales\Model\Convert\Order::class)->itemToCreditmemoItem($orderItem);
        $memoItem->setQty(1);
        self::assertEquals(25, $memoItem->getPrice());
        self::assertEquals(30.25, $memoItem->getPriceInclTax() ?? $memoItem->getOrderItem()->getPriceInclTax());
        self::assertEquals(1, $memoItem->getQty());
    }

    public function testStoreScopedTaxAndTierExclusionReachPurchaseAndPartialReturnOutbox(): void
    {
        $stores = $this->db->fetchCol($this->db->select()->from($this->resource->getTableName('store'), ['store_id'])
            ->where('store_id > ?', 0)->order('store_id')->limit(2));
        self::assertCount(2, $stores, 'This staging test needs two store views.');
        $config = self::$om->create(\Magento\Framework\App\MutableScopeConfig::class);
        $context = self::$om->create(\Magento\Framework\App\Helper\Context::class, ['scopeConfig' => $config]);
        $helper = self::$om->create(Data::class, ['context' => $context]);
        $publisher = self::$om->get(PublisherInterface::class);
        $eligibility = $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility::class);
        $eligibility->method('evaluate')->willReturn(['eligible' => true]);
        $orderData = $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class);
        $purchaseObserver = new \LoyaltyEngage\LoyaltyShop\Observer\PurchaseObserver($helper, $publisher, $eligibility,
            $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\CouponOwnership::class), $orderData);
        $history = new \LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory(
            $this->resource, $this->outbox, $eligibility, $helper, $orderData);
        $returnObserver = new \LoyaltyEngage\LoyaltyShop\Observer\ReturnObserver($helper, $publisher, $history,
            $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\CreditmemoData::class));

        foreach ($stores as $index => $storeId) {
            $storeId = (int) $storeId;
            foreach (['general/module_enable' => 1, 'export/purchase_event' => 1, 'export/return_event' => 1,
                'export/purchase_order_status' => 'processing', 'export/event_price_includes_tax' => $index] as $path => $value) {
                $config->setValue('loyalty/' . $path, $value, 'store', $storeId);
            }
            self::assertSame((bool) $index, $helper->isEventPriceIncludingTax($storeId));
            $order = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create()
                ->setId(random_int(1000000000, 2000000000))->setStoreId($storeId)
                ->setIncrementId('EXPORT-' . bin2hex(random_bytes(6)))->setStatus('processing')
                ->setCreatedAt('2026-10-19 10:00:00')->setCustomerEmail('export-fixture@example.invalid');
            foreach (['PAID' => 0, 'TIER' => 1] as $sku => $excluded) {
                $item = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class)->create()
                    ->setSku($sku)->setPrice(10)->setPriceInclTax(12.10)->setBasePriceInclTax(99)
                    ->setQtyOrdered(3)->setDiscountAmount(2)
                    ->setProductOptions([\LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice::EXCLUDED_OPTION => $excluded]);
                $order->addItem($item);
            }
            $event = new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event(['order' => $order])]);
            $purchaseObserver->execute($event);
            $identity = ['orderId' => $order->getIncrementId(), 'store_id' => $storeId];
            $purchase = $this->outbox->find(PurchaseConsumer::TOPIC, $identity);
            self::assertNotNull($purchase);
            $products = json_decode($purchase['payload'], true)['products'];
            self::assertSame([['sku' => 'PAID', 'price' => $index ? '12.10' : '10.00', 'quantity' => 3]], $products);
            foreach ([1, 2] as $qty) {
                $memo = self::$om->create(\Magento\Sales\Model\Order\Creditmemo::class)
                    ->setId(random_int(1000000000, 2000000000))->setOrder($order)
                    ->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_REFUNDED)->setCreatedAt('2026-10-19 11:00:00');
                foreach ($order->getAllItems() as $item) {
                    $memoItem = self::$om->get(\Magento\Sales\Model\Convert\Order::class)->itemToCreditmemoItem($item);
                    $memoItem->setQty($qty)->setPriceInclTax(null);
                    $memo->addItem($memoItem);
                }
                $returnEvent = new \Magento\Framework\Event\Observer([
                    'event' => new \Magento\Framework\Event(['creditmemo' => $memo])]);
                $returnObserver->execute($returnEvent);
                $returnObserver->execute($returnEvent);
                $row = $this->outbox->find('loyaltyshop.return_event', $identity + ['creditmemo_id' => $memo->getId()]);
                self::assertNotNull($row);
                $products[0]['quantity'] = $qty;
                self::assertSame($products, json_decode($row['payload'], true)['products']);
            }
            $config->setValue('loyalty/export/event_price_includes_tax', !$index, 'store', $storeId);
            $purchaseObserver->execute($event);
            self::assertSame($purchase['payload'], $this->outbox->find(PurchaseConsumer::TOPIC, $identity)['payload'],
                'Changing config must not rewrite an event already queued.');
        }
    }

    public function testMagentoPublisherPersistsAndDeduplicatesWithoutBroker(): void
    {
        $payload = $this->purchase();
        $publisher = self::$om->get(PublisherInterface::class);
        $publisher->publish(PurchaseConsumer::TOPIC, json_encode([$payload]));
        $publisher->publish(PurchaseConsumer::TOPIC, json_encode([$payload]));
        $row = $this->outbox->find(PurchaseConsumer::TOPIC, $payload);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('0', (string) $row['attempts']);
        self::assertSame(1, (int) $this->db->fetchOne($this->db->select()->from($this->outbox->table(), ['COUNT(*)'])
            ->where('event_key = ?', $row['event_key'])));
    }

    public function testLegacyFlagsSurviveFirstSourceWriteAndSubsequentDelivery(): void
    {
        $table = $this->resource->getTableName('sales_order');
        if (!$this->db->tableColumnExists($table, 'loyalty_order_source')) {
            self::markTestSkipped('Legacy-column upgrade fixture requires a pre-2.6.2 database.');
        }
        $this->db->insert($table, ['increment_id' => 'MIG-' . bin2hex(random_bytes(6)),
            'store_id' => $this->storeId, 'loyalty_order_source' => 'api',
            'loyalty_purchase_exported' => 1, 'loyalty_order_place' => 1]);
        $id = (int) $this->db->lastInsertId($table);
        $order = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create()
            ->setId($id)->setStoreId($this->storeId)->setData('loyalty_order_source', 'unknown');
        $data = self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class);
        $data->saveSource($order, 'storefront');
        self::assertTrue($data->isPurchaseExported($order));
        self::assertTrue($data->isOrderPlaced($order));
        self::assertSame('storefront', $data->source($order));
        // A normal resave carrying stale legacy data must not overwrite the new source.
        self::$om->get(\LoyaltyEngage\LoyaltyShop\Observer\PersistOrderDataObserver::class)
            ->execute(new \Magento\Framework\Event\Observer([
                'event' => new \Magento\Framework\Event(['order' => $order]),
            ]));
        self::assertSame('storefront', $data->source($order));
        $data->markDeliveredById($id, $this->storeId, 'loyalty_purchase_exported');
        self::assertTrue($data->isOrderPlaced($order));
        self::assertSame('storefront', $data->source($order));
    }

    public function testFirstDeliverySeedsOtherLegacyFlags(): void
    {
        $table = $this->resource->getTableName('sales_order');
        if (!$this->db->tableColumnExists($table, 'loyalty_order_source')) {
            self::markTestSkipped('Requires legacy columns.');
        }
        $this->db->insert($table, ['increment_id' => 'DEL-' . bin2hex(random_bytes(6)),
            'store_id' => $this->storeId, 'loyalty_order_source' => 'api',
            'loyalty_order_place' => 1]);
        $id = (int) $this->db->lastInsertId($table);
        $data = self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class);
        $data->markDeliveredById($id, $this->storeId, 'loyalty_purchase_exported');
        $order = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create()
            ->setId($id)->setStoreId($this->storeId);
        self::assertTrue($data->isOrderPlaced($order));
        self::assertTrue($data->isPurchaseExported($order));
        self::assertSame('api', $data->source($order));
    }

    public function testFirstOrderSavePersistsItemMarkerAndSourceWithoutSecondSave(): void
    {
        $order = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create();
        $order->setStoreId($this->storeId)->setIncrementId('FIRST-' . bin2hex(random_bytes(6)))
            ->setState('new')->setStatus('pending')->setCustomerEmail('fixture@example.invalid')
            ->setData(\LoyaltyEngage\LoyaltyShop\Model\OrderData::CAPTURED_SOURCE, 'api');
        $order->setPayment(self::$om->get(\Magento\Sales\Model\Order\PaymentFactory::class)->create()->setMethod('checkmo'));
        $factory = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class);
        $reward = $factory->create()->setSku('REWARD')->setQtyOrdered(1)
            ->setProductOptions(['loyalty_locked_qty' => 1]);
        $paid = $factory->create()->setSku('PAID')->setQtyOrdered(1);
        $order->addItem($reward)->addItem($paid);
        self::$om->get(\Magento\Sales\Model\ResourceModel\Order::class)->save($order);
        self::assertGreaterThan(0, (int) $reward->getId());
        $table = $this->resource->getTableName('loyaltyshop_order_item_data');
        self::assertSame(1, (int) $this->db->fetchOne($this->db->select()->from($table, ['loyalty_locked_qty'])
            ->where('order_item_id = ?', $reward->getId())));
        self::assertFalse($this->db->fetchOne($this->db->select()->from($table, ['order_item_id'])
            ->where('order_item_id = ?', $paid->getId())));
        $reloaded = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create();
        self::$om->get(\Magento\Sales\Model\ResourceModel\Order::class)->load($reloaded, $order->getId());
        self::assertSame('api', self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class)->source($reloaded));
        $items = self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyOrderItems::class)
            ->redemptionItems($reloaded->getAllVisibleItems());
        self::assertSame(['REWARD'], array_values(array_map(static fn($item) => $item->getSku(), $items)));
    }

    public function testBatchMarkersSupportLegacyItemsWithoutNewRows(): void
    {
        $factory = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class);
        $legacy = $factory->create()->setSku('LEGACY')->setData('loyalty_locked_qty', 1);
        $modern = $factory->create()->setSku('MODERN')->setProductOptions(['loyalty_locked_qty' => 1]);
        $paid = $factory->create()->setSku('PAID');
        $items = self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyOrderItems::class)
            ->redemptionItems([$legacy, $modern, $paid]);
        self::assertSame(['LEGACY', 'MODERN'], array_values(array_map(static fn($item) => $item->getSku(), $items)));
    }

    public function testLegacyConsumerTransfersMessageWithoutCallingApi(): void
    {
        $payload = $this->purchase();
        self::$om->get(PurchaseConsumer::class)->process(json_encode([$payload]));
        self::assertSame('pending', $this->outbox->find(PurchaseConsumer::TOPIC, $payload)['status']);
    }

    public function testFailureRemainsRetryableThenMarksOrderSentAfterSuccess(): void
    {
        $payload = $this->purchase();
        $orderTable = $this->resource->getTableName('sales_order');
        $this->db->insert($orderTable, ['increment_id' => $payload['orderId'], 'store_id' => $this->storeId,
            'state' => 'processing', 'status' => 'processing', 'customer_email' => $payload['identifier']]);
        $orderId = (int) $this->db->lastInsertId($orderTable);
        $this->outbox->publish(PurchaseConsumer::TOPIC, json_encode($payload));
        $api = $this->createMock(ApiClient::class);
        $calls = 0;
        $api->expects(self::exactly(2))->method('post')->willReturnCallback(function ($url, $body, $storeId) use (&$calls) {
            self::assertSame($this->storeId, $storeId);
            self::assertArrayNotHasKey('store_id', $body[0]);
            if (++$calls === 1) {
                throw new ApiException('temporarily unavailable', 503);
            }
            return ['success' => true];
        });
        $worker = $this->worker($api);
        $worker->execute();
        $row = $this->outbox->find(PurchaseConsumer::TOPIC, $payload);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        $loyaltyOrderTable = $this->resource->getTableName('loyaltyshop_order_data');
        self::assertSame(0, (int) $this->db->fetchOne($this->db->select()->from($loyaltyOrderTable, ['loyalty_purchase_exported'])
            ->where('order_id = ?', $orderId)));
        $this->db->update($this->outbox->table(), ['available_at' => '2000-01-01 00:00:00'], ['entity_id = ?' => $row['entity_id']]);
        $worker->execute();
        self::assertSame('sent', $this->outbox->find(PurchaseConsumer::TOPIC, $payload)['status']);
        self::assertSame(1, (int) $this->db->fetchOne($this->db->select()->from($loyaltyOrderTable, ['loyalty_purchase_exported'])
            ->where('order_id = ?', $orderId)));
    }

    public function testPermanentFailureIsVisibleAndNotRepeated(): void
    {
        $payload = $this->purchase();
        $this->outbox->publish(PurchaseConsumer::TOPIC, json_encode($payload));
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::once())->method('post')->willThrowException(new ApiException('bad request', 400));
        $worker = $this->worker($api);
        $worker->execute();
        $worker->execute();
        self::assertSame('failed', $this->outbox->find(PurchaseConsumer::TOPIC, $payload)['status']);
    }

    public function testDisabledExportStaysPendingWithoutLosingAnAttempt(): void
    {
        $payload = $this->purchase();
        $this->outbox->publish(PurchaseConsumer::TOPIC, json_encode($payload));
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::never())->method('post');
        $this->worker($api, false)->execute();
        $row = $this->outbox->find(PurchaseConsumer::TOPIC, $payload);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
    }

    public function testConfirmedCouponResponseIsReusedWithoutSpendingAgain(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('review-', true));
        $calls = 0;
        $request = static function () use (&$calls) { $calls++; return ['discountCode' => 'TEST-CODE']; };
        $a = $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', $request);
        $b = $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', $request);
        self::assertSame($a, $b);
        self::assertSame(1, $calls);
    }

    public function testUncertainCouponRequestIsNotAutomaticallyRepeated(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('review-', true));
        $calls = 0;
        $request = static function () use (&$calls) { $calls++; throw new \RuntimeException('timeout'); };
        try {
            $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', $request);
        } catch (\RuntimeException $e) {
            self::assertSame('timeout', $e->getMessage());
        }
        try {
            $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', $request);
            self::fail('Uncertain request must not be sent again.');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testRejectedProductAndCouponCanBeRetriedWithoutLosingConfirmation(): void
    {
        foreach (['product', 'discount'] as $operation) {
            $journal = new MutationJournal($this->resource);
            $key = hash('sha256', uniqid('rejected-', true));
            $calls = 0;
            $request = static function () use (&$calls): array {
                if (++$calls <= 2) {
                    throw new ApiRejectionException('AVAILABLE_COINS_INSUFFICIENT');
                }
                return ['success' => true];
            };
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $journal->response($key, $this->storeId, 123, 321, $operation, 'SKU', $request);
                    self::fail('Insufficient balance must be rejected.');
                } catch (ApiRejectionException $e) {
                    $row = $journal->get($key);
                    self::assertSame('rejected', $row['status']);
                    self::assertNull($row['response']);
                    self::assertStringContainsString('AVAILABLE_COINS_INSUFFICIENT', $row['last_error']);
                }
            }
            self::assertSame(['success' => true], $journal->response($key, $this->storeId, 123, 321, $operation, 'SKU', $request));
            self::assertSame('received', $journal->get($key)['status']);
            $journal->complete($key);
            self::assertSame(['success' => true], $journal->response($key, $this->storeId, 123, 321, $operation, 'SKU', $request));
            self::assertSame(3, $calls, 'Confirmed responses must still prevent repeat spending.');
        }
    }

    public function testUnknown400And502RemainUncertainAndCannotBeReplayed(): void
    {
        foreach ([400, 502] as $status) {
            $journal = new MutationJournal($this->resource);
            $key = hash('sha256', uniqid('uncertain-', true));
            $calls = 0;
            $request = static function () use (&$calls, $status): array {
                $calls++;
                throw new ApiException('Loyalty Engage returned HTTP ' . $status, $status);
            };
            try {
                $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', $request);
                self::fail('Expected API failure.');
            } catch (ApiException $e) {
                self::assertSame('uncertain', $journal->get($key)['status']);
            }
            try {
                $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', $request);
                self::fail('Uncertain request must remain blocked.');
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                self::assertStringContainsString('reconciliation', $e->getMessage());
            }
            self::assertSame(1, $calls);
        }
    }

    public function testRecoveryNeverRetriesRejectedRequests(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('recovery-', true));
        try {
            $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', static function (): array {
                throw new ApiRejectionException('SKU_NOT_FOUND');
            });
        } catch (ApiRejectionException $e) {
        }
        $journal->setRecoveryKey($key);
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('confirmed journal response');
        $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', static function (): array {
            self::fail('Recovery must never send another purchase.');
        });
    }

    public function testUncertainRequestDoesNotBlockAnotherSkuForTheSameCustomer(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('uncertain-sku-', true));
        try {
            $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU-A', static function (): array {
                throw new \RuntimeException('timeout');
            });
        } catch (\RuntimeException $e) {
        }
        self::assertSame('uncertain', $journal->get($key)['status']);
        $otherKey = hash('sha256', uniqid('other-sku-', true));
        self::assertSame(['success' => true], $journal->response(
            $otherKey, $this->storeId, 123, 321, 'product', 'SKU-B', static fn() => ['success' => true]
        ));
        self::assertSame('uncertain', $journal->get($key)['status']);
    }

    public function testTimeoutAfterRejectedRetryRestoresUncertainProtection(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('retry-timeout-', true));
        $calls = 0;
        $request = static function () use (&$calls): array {
            if (++$calls === 1) {
                throw new ApiRejectionException('AVAILABLE_COINS_INSUFFICIENT');
            }
            throw new \RuntimeException('timeout');
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', $request);
                self::fail('Expected a failure.');
            } catch (\RuntimeException $e) {
                self::assertSame($attempt === 0 ? 'rejected' : 'uncertain', $journal->get($key)['status']);
            }
        }
        try {
            $journal->response($key, $this->storeId, 123, 321, 'product', 'SKU', $request);
            self::fail('A later timeout must not inherit the previous permission to retry.');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }
        self::assertSame(2, $calls);
    }

    public function testCouponUncertaintyCannotBeResolvedUsingPhysicalCartEvidence(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('coupon-timeout-', true));
        try {
            $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', static function (): array {
                throw new \RuntimeException('timeout');
            });
        } catch (\RuntimeException $e) {
        }
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('reconciliation');
        $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', static function (): array {
            self::fail('A coupon must never be repurchased during recovery.');
        }, static function (): ?array {
            self::fail('A physical cart cannot confirm coupon issuance.');
        });
    }

    public function testPartialCustomerUpdatesPreserveOtherFields(): void
    {
        $customerId = (int) $this->db->fetchOne($this->db->select()->from($this->resource->getTableName('customer_entity'), ['entity_id'])->limit(1));
        self::assertGreaterThan(0, $customerId, 'Local Magento requires a customer fixture.');
        $provider = self::$om->get(CustomerLoyaltyDataProvider::class);
        $provider->saveCustomerStoreData($customerId, $this->storeId, ['le_points' => 100, 'le_available_coins' => 50]);
        $provider->saveCustomerStoreData($customerId, $this->storeId, ['le_points' => 200]);
        $row = $this->db->fetchRow($this->db->select()->from($this->resource->getTableName('loyaltyshop_customer_store_data'))
            ->where('customer_id = ?', $customerId)->where('store_id = ?', $this->storeId));
        self::assertSame(200, (int) $row['le_points']);
        self::assertSame(50, (int) $row['le_available_coins']);
    }

    public function testMissingCredentialsDoNotLeaveAnUncertainReservation(): void
    {
        $journal = new MutationJournal($this->resource);
        $key = hash('sha256', uniqid('review-', true));
        try {
            $journal->response($key, $this->storeId, 123, 321, 'discount', 'SKU', static function () {
                throw new \LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException('not configured');
            });
            self::fail('Missing credentials must abort before transport.');
        } catch (\LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException $e) {
            self::assertNull($journal->get($key));
        }
        self::assertSame(['discountCode' => 'FIXTURE'], $journal->response(
            $key, $this->storeId, 123, 321, 'discount', 'SKU', static fn() => ['discountCode' => 'FIXTURE']
        ));
    }

    public function testCredentialRepairEncryptsPlaintextOnceAndPreservesStoreScope(): void
    {
        $table = $this->resource->getTableName('core_config_data');
        $paths = ['loyalty/general/tenant_id', 'loyalty/general/bearer_token'];
        foreach ($paths as $path) {
            $this->db->insertOnDuplicate($table, ['scope' => 'stores', 'scope_id' => $this->storeId,
                'path' => $path, 'value' => 'review-plaintext-fixture'], ['value']);
        }
        $patch = self::$om->get(\LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\EncryptLegacyCredentials::class);
        $patch->apply();
        $select = $this->db->select()->from($table, ['value'])->where('scope = ?', 'stores')
            ->where('scope_id = ?', $this->storeId)->where('path IN (?)', $paths)->order('path');
        $encrypted = $this->db->fetchCol($select);
        self::assertCount(2, $encrypted);
        foreach ($encrypted as $value) {
            self::assertNotSame('review-plaintext-fixture', $value);
            self::assertSame('review-plaintext-fixture', self::$om->get(\Magento\Framework\Encryption\EncryptorInterface::class)->decrypt($value));
        }
        $patch->apply();
        self::assertSame($encrypted, $this->db->fetchCol($select));
    }

    private function purchase(): array
    {
        return ['event' => 'Purchase', 'identifier' => 'review@example.invalid', 'store_id' => $this->storeId,
            'orderId' => 'test-' . bin2hex(random_bytes(6)), 'orderDate' => '2026-09-24T12:00:00+00:00',
            'products' => [['sku' => 'TEST', 'price' => '10.00', 'quantity' => 1.5]]];
    }

    /** @dataProvider recoverableRewardFailures */
    public function testFailedRewardCanBeRecoveredAndPaidLinesStaySeparate(bool $timeout, bool $remoteEmpty): void
    {
        $customerTable = $this->resource->getTableName('customer_entity');
        $store = self::$om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore($this->storeId);
        self::$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore($this->storeId);
        $this->db->insert($customerTable, ['website_id' => $store->getWebsiteId(), 'store_id' => $this->storeId,
            'email' => bin2hex(random_bytes(8)) . '@example.invalid', 'firstname' => 'Review', 'lastname' => 'Fixture',
            'group_id' => 1, 'is_active' => 1]);
        $customerId = (int) $this->db->lastInsertId($customerTable);
        $sku = '24-MB01';
        $api = $this->createMock(ApiClient::class);
        $calls = 0;
        $api->expects(self::exactly($timeout ? 1 : 2))->method('post')->willReturnCallback(static function () use (&$calls, $timeout): array {
            if (++$calls === 1) {
                if ($timeout) {
                    throw new ApiException('timeout');
                }
                throw new ApiRejectionException('AVAILABLE_COINS_INSUFFICIENT');
            }
            return ['success' => true];
        });
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isLoyaltyProduct')->willReturnCallback(static fn($item) => (bool) $item->getData('loyalty_locked_qty')
            || ($item->getOptionByCode('loyalty_locked_qty') && $item->getOptionByCode('loyalty_locked_qty')->getValue() === '1'));
        $helper->method('hashEmail')->willReturn(hash('sha256', 'fixture'));
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $helper->method('successResponse')->willReturnCallback(static fn($r, $m) => $r->setSuccess(true)->setMessage($m));
        $helper->method('errorResponse')->willReturnCallback(static fn($r, $m, $type, $status = 400) =>
            $r->setSuccess(false)->setMessage($m)->setErrorType($type . '_' . $status));
        $api->expects($timeout ? self::once() : self::never())->method('get')
            ->with('https://example.invalid/api/v1/loyalty/shop/' . hash('sha256', 'fixture') . '/cart', [], $this->storeId)
            ->willReturn(['reservedCoins' => $remoteEmpty ? 0 : 800, 'availableCoins' => 100, 'products' => $remoteEmpty ? [] : [
                ['sku' => $sku, 'quantity' => 1, 'coinPrice' => 800],
            ]]);
        $reconciler = new \LoyaltyEngage\LoyaltyShop\Model\PhysicalCartReconciler($this->resource, $api, $helper);
        $cart = self::$om->create(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart::class,
            ['api' => $api, 'helper' => $helper, 'physicalReconciler' => $reconciler]);
        $quote = $cart->getOrCreateCustomerQuote($customerId);
        $product = self::$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($sku, false, $this->storeId);
        $paid = $quote->addProduct(clone $product, new \Magento\Framework\DataObject(['qty' => 2]));
        self::assertIsNotString($paid);
        $quote->collectTotals();
        self::$om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
        $paidId = (int) $paid->getId();
        $rejected = $cart->addProduct($customerId, $sku);
        self::assertFalse($rejected->getSuccess());
        self::assertSame($timeout ? 'system_error_502' : 'available_coins_insufficient_400', $rejected->getErrorType());
        if (!$timeout) {
            self::assertStringContainsString('enough available coins', $rejected->getMessage());
        }
        self::assertCount(1, $cart->getOrCreateCustomerQuote($customerId)->getAllVisibleItems(),
            'Rejected reward must not persist an unpaid/unreserved line.');
        if ($timeout) {
            self::$om->create(\LoyaltyEngage\LoyaltyShop\Cron\RecoverMutations::class, ['cart' => $cart])->execute();
            $row = $this->db->fetchRow($this->db->select()->from($this->resource->getTableName('loyaltyshop_mutation'))
                ->where('quote_id = ?', $quote->getId())->where('sku = ?', $sku));
            if ($remoteEmpty) {
                self::assertSame('uncertain', $row['status']);
                self::assertNull($row['response']);
                self::assertSame(1, (int) $row['attempts']);
                self::assertCount(1, $cart->getOrCreateCustomerQuote($customerId)->getAllVisibleItems());
                return;
            }
            self::assertSame('applied', $row['status']);
            self::assertSame('loyalty_cart', json_decode($row['response'], true)['reconciled_from']);
        } else {
            $result = $cart->addProduct($customerId, $sku);
            self::assertTrue($result->getSuccess(), $result->getMessage());
        }
        $quote = $cart->getOrCreateCustomerQuote($customerId);
        $items = $quote->getAllVisibleItems();
        self::assertCount(2, $items);
        $loyalty = null;
        foreach ($items as $item) {
            if ((int) $item->getId() === $paidId) {
                self::assertSame(2.0, (float) $item->getQty());
                self::assertGreaterThan(0, (float) $item->getPrice());
            } else {
                $loyalty = $item;
                self::assertSame(0.0, (float) $item->getCustomPrice());
            }
        }
        self::assertNotNull($loyalty);
        self::assertTrue($cart->addProduct($customerId, $sku)->getSuccess());
        $quote->removeItem($loyalty->getId());
        self::$om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
        $row = $this->outbox->find('loyaltyshop.free_product_remove_event',
            ['store_id' => $this->storeId, 'removal_id' => (int) $loyalty->getId()]);
        self::assertNotNull($row, 'A committed quote removal must persist its store-scoped event.');
        self::assertSame('pending', $row['status']);
        self::assertCount(1, $quote->getAllVisibleItems());
    }

    public static function recoverableRewardFailures(): array
    {
        return ['definitive rejection' => [false, false], 'timeout with remote reservation' => [true, false],
            'timeout with empty remote cart' => [true, true]];
    }

    public function testCartLookupCannotConfirmMissingMalformedOrDuplicateReservations(): void
    {
        $quote = self::$om->create(\Magento\Quote\Model\Quote::class)->setData([
            'entity_id' => 987654, 'store_id' => $this->storeId, 'customer_id' => 987654,
            'customer_email' => 'reconcile-' . bin2hex(random_bytes(6)) . '@example.invalid', 'is_active' => 1,
        ]);
        $cases = [[], ['products' => []], ['products' => null], ['products' => ['SKU' => ['quantity' => 1]]],
            ['products' => [['sku' => 'OTHER', 'quantity' => 1, 'coinPrice' => 800]]],
            ['products' => [['sku' => 'SKU', 'quantity' => 2, 'coinPrice' => 800]]],
            ['products' => [['sku' => 'SKU', 'quantity' => 1]]],
            ['products' => [['sku' => 'SKU', 'quantity' => 1, 'coinPrice' => 800], ['sku' => 'SKU', 'quantity' => 1, 'coinPrice' => 800]]],
        ];
        $helper = $this->createMock(Data::class);
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $helper->method('hashEmail')->willReturn('fixture');
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::never())->method('post');
        $api->expects(self::exactly(count($cases)))->method('get')->willReturnOnConsecutiveCalls(...$cases);
        $reconciler = new \LoyaltyEngage\LoyaltyShop\Model\PhysicalCartReconciler($this->resource, $api, $helper);
        foreach ($cases as $case) {
            self::assertNull($reconciler->confirm($quote, 'SKU', 'fixture'));
        }
    }

    public function testCartLookupCannotClaimAnotherPendingOperationOrRemoval(): void
    {
        $quote = self::$om->create(\Magento\Quote\Model\Quote::class)->setData([
            'entity_id' => 987654, 'store_id' => $this->storeId, 'customer_id' => 987654,
            'customer_email' => 'reconcile-' . bin2hex(random_bytes(6)) . '@example.invalid', 'is_active' => 1,
        ]);
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::never())->method('get');
        $api->expects(self::never())->method('post');
        $reconciler = new \LoyaltyEngage\LoyaltyShop\Model\PhysicalCartReconciler(
            $this->resource, $api, $this->createMock(Data::class)
        );
        $table = $this->resource->getTableName('loyaltyshop_mutation');
        $key = hash('sha256', uniqid('conflict-', true));
        $this->db->insert($table, ['operation_key' => $key, 'customer_id' => 987654,
            'quote_id' => 987655, 'store_id' => $this->storeId, 'operation' => 'product', 'sku' => 'SKU', 'status' => 'uncertain']);
        self::assertNull($reconciler->confirm($quote, 'SKU', 'current-key'));
        $this->db->delete($table, ['operation_key = ?' => $key]);
        $this->outbox->publish('loyaltyshop.free_product_remove_event', json_encode([
            'email' => $quote->getCustomerEmail(), 'sku' => 'SKU', 'quantity' => 1,
            'store_id' => $this->storeId, 'quote_id' => $quote->getId(), 'removal_id' => random_int(10000000, 99999999),
        ]));
        self::assertNull($reconciler->confirm($quote, 'SKU', 'current-key'));
    }

    public function testCouponCodeCollisionCannotReuseAMerchantRule(): void
    {
        $code = 'REVIEW-' . bin2hex(random_bytes(6));
        $rule = self::$om->get(\Magento\SalesRule\Model\RuleFactory::class)->create();
        $websiteId = (int) self::$om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore($this->storeId)->getWebsiteId();
        $rule->setName('Merchant fixture')->setIsActive(1)->setSimpleAction('by_percent')->setDiscountAmount(10)
            ->setWebsiteIds([$websiteId])->setCustomerGroupIds([1])->setCouponType(2)->setCouponCode($code)->save();
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('conflicts');
        self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\CouponRules::class)->ensure($code, 10, false);
    }

    public function testRefundWaitsForPurchaseDelivery(): void
    {
        $payload = $this->purchase();
        $this->outbox->publish(PurchaseConsumer::TOPIC, json_encode($payload));
        $refund = array_replace($payload, ['event' => 'Return', 'creditmemo_id' => random_int(100000, 999999)]);
        $this->outbox->publish('loyaltyshop.return_event', json_encode($refund));
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::once())->method('post')->willThrowException(new ApiException('unavailable', 503));
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isPurchaseExportEnabled')->willReturn(true);
        $helper->method('isReturnExportEnabled')->willReturn(true);
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturn(false);
        $worker = new DeliverEvents($this->resource, $this->outbox, $lock, $api, new NullLogger(),
            self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class),
            self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\CreditmemoData::class), $maintenance, [
            PurchaseConsumer::TOPIC => new PurchaseConsumer($helper, $api),
            'loyaltyshop.return_event' => new \LoyaltyEngage\LoyaltyShop\Model\Queue\ReturnConsumer($helper, $api),
        ]);
        $worker->execute();
        $row = $this->outbox->find('loyaltyshop.return_event', $refund);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertStringContainsString('Waiting', $row['last_error']);
    }

    private function worker(ApiClient $api, bool $enabled = true): DeliverEvents
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isPurchaseExportEnabled')->willReturn($enabled);
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturn(false);
        return new DeliverEvents($this->resource, $this->outbox, $lock, $api, new NullLogger(),
            self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\OrderData::class),
            self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\CreditmemoData::class), $maintenance,
            [PurchaseConsumer::TOPIC => new PurchaseConsumer($helper, $api)]);
    }

    public function testFreeShippingUsesQuoteCustomerAndStoreWithoutSession(): void
    {
        $customerId = (int) $this->db->fetchOne($this->db->select()->from($this->resource->getTableName('customer_entity'), ['entity_id'])->limit(1));
        $provider = self::$om->get(CustomerLoyaltyDataProvider::class);
        $provider->saveCustomerStoreData($customerId, 1, ['le_current_tier' => 'Gold']);
        $provider->saveCustomerStoreData($customerId, 2, ['le_current_tier' => 'Bronze']);
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isFreeShippingEnabled')->willReturn(true);
        $helper->method('getFreeShippingTiersArray')->willReturn(['Gold']);
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::never())->method('get');
        $checker = new \LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker($api,
            self::$om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class), $helper, $provider);
        $quote = self::$om->get(\Magento\Quote\Model\QuoteFactory::class)->create();
        $quote->setStoreId(1)->setCustomerId($customerId)->setCustomerIsGuest(false);
        self::assertTrue($checker->qualifiesQuote($quote));
        $quote->setStoreId(2);
        self::assertFalse($checker->qualifiesQuote($quote));
        $quote->setStoreId(1)->setCustomerIsGuest(true);
        self::assertFalse($checker->qualifiesQuote($quote));
    }

    public function testCouponRuleReuseIsWebsiteScoped(): void
    {
        $firstCode = 'TEST-' . bin2hex(random_bytes(6));
        $secondCode = 'TEST-' . bin2hex(random_bytes(6));
        $websiteTable = $this->resource->getTableName('store_website');
        $this->db->insert($websiteTable, ['code' => 'review_' . bin2hex(random_bytes(4)), 'name' => 'Review fixture']);
        $websiteId = (int) $this->db->lastInsertId($websiteTable);
        self::$om->get(\LoyaltyEngage\LoyaltyShop\Model\CouponRules::class)->ensure($firstCode, 13.25, false);
        $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $stores->method('getStore')->willReturn($store);
        self::$om->create(\LoyaltyEngage\LoyaltyShop\Model\CouponRules::class, ['stores' => $stores])->ensure($secondCode, 13.25, false);
        $table = $this->resource->getTableName('salesrule_coupon');
        $first = $this->db->fetchOne($this->db->select()->from($table, ['rule_id'])->where('code = ?', $firstCode));
        $second = $this->db->fetchOne($this->db->select()->from($table, ['rule_id'])->where('code = ?', $secondCode));
        self::assertNotSame($first, $second);
        self::assertSame($websiteId, (int) $this->db->fetchOne($this->db->select()
            ->from($this->resource->getTableName('salesrule_website'), ['website_id'])->where('rule_id = ?', $second)));
    }

    public function testRepairPatchDisablesInvalidLegacyShippingRule(): void
    {
        $table = $this->resource->getTableName('salesrule');
        $this->db->insert($table, ['name' => 'Loyalty Free Shipping - Brons Tier',
            'simple_action' => 'free_shipping', 'is_active' => 1, 'discount_amount' => 10]);
        $id = (int) $this->db->lastInsertId($table);
        self::$om->get(\LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\RepairLoyaltyFreeShippingRule::class)->apply();
        $row = $this->db->fetchRow($this->db->select()->from($table)->where('rule_id = ?', $id));
        self::assertSame('by_percent', $row['simple_action']);
        self::assertSame(0, (int) $row['is_active']);
        self::assertSame(0.0, (float) $row['discount_amount']);
        self::assertSame(2, (int) $row['simple_free_shipping']);
    }
}
