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
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
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
        self::assertSame(0, (int) $this->db->fetchOne($this->db->select()->from($orderTable, ['loyalty_purchase_exported'])
            ->where('entity_id = ?', $orderId)));
        $this->db->update($this->outbox->table(), ['available_at' => '2000-01-01 00:00:00'], ['entity_id = ?' => $row['entity_id']]);
        $worker->execute();
        self::assertSame('sent', $this->outbox->find(PurchaseConsumer::TOPIC, $payload)['status']);
        self::assertSame(1, (int) $this->db->fetchOne($this->db->select()->from($orderTable, ['loyalty_purchase_exported'])
            ->where('entity_id = ?', $orderId)));
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

    public function testPaidAndLoyaltyLinesStaySeparateAndOnlyLoyaltyIsRemoved(): void
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
        $api->expects(self::once())->method('post')->willReturn(['success' => true]);
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isLoyaltyProduct')->willReturnCallback(static fn($item) => (bool) $item->getData('loyalty_locked_qty')
            || ($item->getOptionByCode('loyalty_locked_qty') && $item->getOptionByCode('loyalty_locked_qty')->getValue() === '1'));
        $helper->method('hashEmail')->willReturn(hash('sha256', 'fixture'));
        $helper->method('getApiUrl')->willReturn('https://example.invalid');
        $helper->method('successResponse')->willReturnCallback(static fn($r, $m) => $r->setSuccess(true)->setMessage($m));
        $helper->method('errorResponse')->willReturnCallback(static fn($r, $m) => $r->setSuccess(false)->setMessage($m));
        $cart = self::$om->create(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart::class, ['api' => $api, 'helper' => $helper]);
        $quote = $cart->getOrCreateCustomerQuote($customerId);
        $product = self::$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($sku, false, $this->storeId);
        $paid = $quote->addProduct(clone $product, new \Magento\Framework\DataObject(['qty' => 2]));
        self::assertIsNotString($paid);
        $quote->collectTotals();
        self::$om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
        $paidId = (int) $paid->getId();
        $result = $cart->addProduct($customerId, $sku);
        self::assertTrue($result->getSuccess(), $result->getMessage());
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
        $worker = new DeliverEvents($this->resource, $this->outbox, $lock, $api, new NullLogger(), $maintenance, [
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
        return new DeliverEvents($this->resource, $this->outbox, $lock, $api, new NullLogger(), $maintenance,
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
