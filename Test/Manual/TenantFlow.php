<?php
declare(strict_types=1);

/** Explicit, persistent local acceptance tests. This is not an automatic test suite. */
if (PHP_SAPI !== 'cli' || !getenv('LE_TEST_EMAIL') || !getenv('LE_TEST_RUN')) {
    exit("CLI only. Set LE_TEST_EMAIL and LE_TEST_RUN. Never run against a production Magento database.\n");
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$storeId = (int) (getenv('LE_TEST_STORE') ?: 1);
$stores->setCurrentStore($storeId);
$store = $stores->getStore();
if (parse_url($store->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new \RuntimeException('This runner is restricted to the local magento.test environment.');
}
$email = getenv('LE_TEST_EMAIL');
$run = getenv('LE_TEST_RUN');
if (!preg_match('/^LE-E2E-[a-zA-Z0-9-]+$/', $run)) {
    throw new \RuntimeException('Choose an identifiable LE-E2E- prefixed run name.');
}
$helper = $om->get(\LoyaltyEngage\LoyaltyShop\Helper\Data::class);
$api = $om->get(\LoyaltyEngage\LoyaltyShop\Service\ApiClient::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $resource->getConnection();
$customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->get($email, (int) $store->getWebsiteId());
$customerId = (int) $customer->getId();
$output = __DIR__ . '/output';
if (!is_dir($output)) {
    mkdir($output, 0700, true);
}
$stateFile = $output . '/' . $run . '.json';
$runLock = fopen($stateFile . '.lock', 'c');
if (!$runLock || !flock($runLock, LOCK_EX | LOCK_NB)) {
    throw new \RuntimeException('Another command is using this test run; wait for it to finish.');
}
chmod($stateFile . '.lock', 0600);
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR) : [
    'run' => $run, 'email' => $email, 'customer_id' => $customerId,
    'started_at' => gmdate(DATE_ATOM), 'cases' => [], 'steps' => [],
    'outbox_start' => (int) $db->fetchOne($db->select()->from($resource->getTableName('loyaltyshop_outbox'), ['MAX(entity_id)'])),
];
if ($state['email'] !== $email || $state['customer_id'] !== $customerId) {
    throw new \RuntimeException('Run belongs to a different test customer.');
}
function checkpoint(): void
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($stateFile, 0600);
}
function note(string $step, array $data): void
{
    global $state;
    $row = ['at' => gmdate(DATE_ATOM), 'step' => $step, 'data' => $data];
    $state['steps'][] = $row;
    checkpoint();
    $console = $row;
    if (isset($console['data']['events'])) {
        $console['data']['events'] = array_map(static fn(array $event): array => array_intersect_key($event,
            array_flip(['entity_id', 'topic', 'store_id', 'status', 'attempts', 'last_error'])), $console['data']['events']);
    }
    echo json_encode($console, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
function remoteStatus(): array
{
    global $helper, $api, $email, $storeId;
    return $api->get(rtrim($helper->getApiUrl($storeId), '/') . '/api/v1/contact/'
        . $helper->hashEmail($email) . '/loyalty_status', [], $storeId);
}
function outbox(): array
{
    global $db, $resource, $state;
    return $db->fetchAll($db->select()->from($resource->getTableName('loyaltyshop_outbox'),
        ['entity_id', 'topic', 'store_id', 'status', 'attempts', 'last_error', 'payload', 'sent_at'])
        ->where('entity_id > ?', $state['outbox_start'])->order('entity_id'));
}
function quoteForCase(string $case): \Magento\Quote\Model\Quote
{
    global $om, $state, $customerId;
    $id = $state['cases'][$case]['quote_id'] ?? 0;
    if (!$id) {
        throw new \RuntimeException('Create the case quote first.');
    }
    $quote = $om->get(\Magento\Quote\Api\CartRepositoryInterfaceFactory::class)->create()->get((int) $id);
    if ((int) $quote->getCustomerId() !== $customerId) {
        throw new \RuntimeException('Unexpected quote owner.');
    }
    return $quote;
}
function orderForCase(string $case): \Magento\Sales\Model\Order
{
    global $om, $state, $customerId, $run;
    $order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get((int) ($state['cases'][$case]['order_id'] ?? 0));
    if ((int) $order->getCustomerId() !== $customerId || !str_starts_with($order->getIncrementId(), $run)) {
        throw new \RuntimeException('Refusing to modify an order outside this test run.');
    }
    return $order;
}
checkpoint();
$command = $argv[1] ?? 'status';
$case = $argv[2] ?? 'purchase';
try {
    switch ($command) {
        case 'cart-status':
            note($command, ['response' => $api->get(rtrim($helper->getApiUrl($storeId), '/')
                . '/api/v1/loyalty/shop/' . rawurlencode($helper->hashEmail($email)) . '/cart', [], $storeId)]);
            break;

        case 'cancel-response-loss':
            $quote = quoteForCase($case);
            $sku = $argv[3] ?? '24-MB01';
            $attempt = array_filter($state['steps'], static fn($step) => $step['step'] === 'add-response-loss'
                && (int) ($step['data']['quote_id'] ?? $state['cases']['recovery']['quote_id'] ?? 0) === (int) $quote->getId()
                && ($step['data']['posts'] ?? 0) === 1 && !$step['data']['success']);
            if (!$attempt || array_filter($quote->getAllVisibleItems(), static fn($i) => $helper->isLoyaltyProduct($i, true))) {
                throw new \RuntimeException('Only this run\'s lost-response fixture may be cancelled.');
            }
            $table = $resource->getTableName('loyaltyshop_mutation');
            $rows = $db->fetchAll($db->select()->from($table)->where('quote_id = ?', (int) $quote->getId())
                ->where('customer_id = ?', $customerId)->where('sku = ?', $sku)->where('status = ?', 'uncertain'));
            if (count($rows) !== 1) { throw new \RuntimeException('Expected exactly one uncertain fixture.'); }
            $url = rtrim($helper->getApiUrl($storeId), '/') . '/api/v1/loyalty/shop/'
                . rawurlencode($helper->hashEmail($email)) . '/cart';
            $remote = $api->get($url, [], $storeId);
            if (count($remote['products'] ?? []) !== 1 || $remote['products'][0]['sku'] !== $sku
                || (float) $remote['products'][0]['quantity'] !== 1.0) {
                throw new \RuntimeException('Unexpected cart; preserve it for inspection.');
            }
            $api->delete($url . '/remove', ['sku' => $sku, 'quantity' => 1], $storeId);
            $after = $api->get($url, [], $storeId);
            if ($after['products'] !== [] || (float) $after['reservedCoins'] !== 0.0) {
                throw new \RuntimeException('Test cancellation not yet confirmed.');
            }
            $db->update($table, ['status' => 'released', 'last_error' => 'Controlled acceptance fixture cancelled after confirmed remote removal.'],
                ['operation_key = ?' => $rows[0]['operation_key'], 'status = ?' => 'uncertain']);
            note($command, ['quote_id' => (int) $quote->getId(), 'sku' => $sku, 'remote' => $after]);
            break;

        case 'add-response-loss':
        case 'recover-readonly':
            $quote = quoteForCase($case);
            $sku = $argv[3] ?? '24-MB01';
            $transport = new class($om->get(\Magento\Framework\HTTP\Client\Curl::class),
                $om->get(\Magento\Framework\Serialize\Serializer\Json::class), $helper) extends \LoyaltyEngage\LoyaltyShop\Service\ApiClient {
                public bool $loseResponse = false;
                public int $posts = 0;
                public function post(string $url, array $body = [], ?int $storeId = null): array
                {
                    ++$this->posts;
                    if (!$this->loseResponse) { throw new \RuntimeException('Read-only recovery must not POST.'); }
                    parent::post($url, $body, $storeId);
                    throw new \RuntimeException('TEST ONLY: successful response deliberately lost after remote reservation.');
                }
            };
            $transport->loseResponse = $command === 'add-response-loss';
            $cart = $om->get(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart::class);
            $property = new \ReflectionProperty(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart::class, 'api');
            $property->setAccessible(true);
            $property->setValue($cart, $transport);
            $result = $cart->addProduct($customerId, $sku);
            $rows = $db->fetchAll($db->select()->from($resource->getTableName('loyaltyshop_mutation'),
                ['status', 'response'])->where('quote_id = ?', (int) $quote->getId()));
            note($command, ['quote_id' => (int) $quote->getId(), 'sku' => $sku, 'success' => $result->getSuccess(), 'message' => $result->getMessage(), 'posts' => $transport->posts,
                'journals' => $rows, 'remote' => remoteStatus()]);
            if (($command === 'add-response-loss' && ($result->getSuccess() || $transport->posts !== 1))
                || ($command === 'recover-readonly' && (!$result->getSuccess() || $transport->posts !== 0))) {
                throw new \RuntimeException('Live response-loss/recovery acceptance failed.');
            }
            break;

        case 'fixture-tier':
        case 'restore-tier':
            $products = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            $product = $products->get('24-MB01', true, 0, true);
            if ($command === 'fixture-tier') {
                if (isset($state['tier_backup'])) { throw new \RuntimeException('Tier fixture already exists.'); }
                $state['tier_backup'] = ['original' => $product->getData('tier_price') ?: [], 'restored' => false];
                checkpoint();
                $om->get(\Magento\Catalog\Api\ProductTierPriceManagementInterface::class)->add('24-MB01', 'all', 30, 2);
                $state['tier_backup']['fixture'] = $products->get('24-MB01', true, 0, true)->getData('tier_price');
            } else {
                $backup = $state['tier_backup'] ?? null;
                if (!$backup || $backup['restored']) { throw new \RuntimeException('No active tier fixture.'); }
                if ($product->getData('tier_price') != ($backup['fixture'] ?? null)) {
                    throw new \RuntimeException('Tier prices changed externally; inspect before restoring.');
                }
                $product->setData('tier_price', $backup['original']);
                $products->save($product);
                $state['tier_backup']['restored'] = true;
            }
            note($command, ['sku' => '24-MB01', 'restored' => $state['tier_backup']['restored']]);
            break;

        case 'mixed-tier-cart':
            $quote = quoteForCase($case);
            $products = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($helper->isLoyaltyProduct($item, true)) { throw new \RuntimeException('Preserve reward items.'); }
                $quote->removeItem((int) $item->getId());
            }
            foreach (['24-MB01' => 2, '24-MB02' => 1] as $sku => $quantity) {
                $added = $quote->addProduct($products->get($sku, false, $storeId, true),
                    new \Magento\Framework\DataObject(['qty' => $quantity]));
                if (is_string($added)) { throw new \RuntimeException($added); }
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            note($command, ['items' => array_map(static fn($i) => ['sku' => $i->getSku(), 'qty' => $i->getQty(),
                'price' => $i->getPrice(), 'discount' => $i->getDiscountAmount()], $quote->getAllVisibleItems()),
                'total' => $quote->getGrandTotal()]);
            break;

        case 'coupon-status':
            $quote = quoteForCase($case);
            if (!$quote->getCouponCode()) { throw new \RuntimeException('No coupon in this test case.'); }
            $result = $api->get(rtrim($helper->getApiUrl($storeId), '/') . '/api/v1/discount/'
                . rawurlencode($quote->getCouponCode()), [], $storeId);
            note($command, ['response' => $result]);
            break;

        case 'prepare-browser':
            $quote = quoteForCase($case);
            $quote->setReservedOrderId($run . '-' . strtoupper($case));
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            note($command, ['quote_id' => $quote->getId(), 'reserved_order_id' => $quote->getReservedOrderId()]);
            break;

        case 'adopt-browser-order':
            $quote = quoteForCase($case);
            $row = $db->fetchRow($db->select()->from($resource->getTableName('sales_order'))
                ->where('quote_id = ?', (int) $quote->getId())->where('customer_id = ?', $customerId)
                ->where('increment_id = ?', $run . '-' . strtoupper($case)));
            if (!$row || (int) $row['store_id'] !== $storeId || $row['customer_email'] !== $email) {
                throw new \RuntimeException('No matching browser order owned by this run.');
            }
            $state['cases'][$case]['order_id'] = (int) $row['entity_id'];
            $state['cases'][$case]['increment_id'] = $row['increment_id'];
            note($command, ['order_id' => (int) $row['entity_id'], 'increment_id' => $row['increment_id'],
                'status' => $row['status'], 'total' => $row['grand_total'], 'events' => outbox()]);
            break;

        case 'test-config':
        case 'restore-config':
            $allowed = ['loyalty/general/minimum_order_value', 'loyalty/export/event_price_includes_tax',
                'loyalty/export/exclude_tier_priced_items', 'sales_email/order/enabled'];
            $writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
            if ($command === 'test-config') {
                $path = $argv[2] ?? '';
                $value = $argv[3] ?? '';
                if (!in_array($path, $allowed, true) || !is_numeric($value) || (float) $value < 0) {
                    throw new \RuntimeException('Unsupported test configuration.');
                }
                $key = $storeId . ':' . $path;
                if (!isset($state['config_backup'][$key])) {
                    $original = $db->fetchRow($db->select()->from($resource->getTableName('core_config_data'))
                        ->where('scope = ?', 'stores')->where('scope_id = ?', $storeId)->where('path = ?', $path));
                    $state['config_backup'][$key] = ['store_id' => $storeId, 'path' => $path,
                        'original' => $original ?: null, 'test_value' => $value, 'restored' => false];
                } else {
                    $state['config_backup'][$key]['test_value'] = $value;
                    $state['config_backup'][$key]['restored'] = false;
                }
                checkpoint();
                $writer->save($path, $value, 'stores', $storeId);
            } else {
                foreach ($state['config_backup'] ?? [] as $key => $backup) {
                    if ($backup['restored']) { continue; }
                    $current = $db->fetchOne($db->select()->from($resource->getTableName('core_config_data'), ['value'])
                        ->where('scope = ?', 'stores')->where('scope_id = ?', $backup['store_id'])
                        ->where('path = ?', $backup['path']));
                    if ((string) $current !== (string) $backup['test_value']) {
                        throw new \RuntimeException('Test configuration changed externally; inspect before restoring.');
                    }
                    if ($backup['original'] === null) {
                        $writer->delete($backup['path'], 'stores', $backup['store_id']);
                    } else {
                        $writer->save($backup['path'], $backup['original']['value'], 'stores', $backup['store_id']);
                    }
                    $state['config_backup'][$key]['restored'] = true;
                    checkpoint();
                }
            }
            $cache = $om->get(\Magento\Framework\App\Cache\TypeListInterface::class);
            foreach (['config', 'layout', 'block_html', 'full_page'] as $type) { $cache->cleanType($type); }
            note($command, ['store_id' => $storeId, 'path' => $argv[2] ?? null]);
            break;

        case 'recalculate':
            $quote = quoteForCase($case);
            $quote->getBillingAddress();
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            note($command, ['total' => $quote->getGrandTotal(), 'items' => array_map(static fn($i) => [
                'sku' => $i->getSku(), 'price' => $i->getPrice(), 'qty' => $i->getQty(),
                'discount' => $i->getDiscountAmount()], $quote->getAllVisibleItems())]);
            break;

        case 'quantity':
            $quote = quoteForCase($case);
            $quantity = (float) ($argv[3] ?? 1);
            if ($quantity < 1 || $quantity > 3) { throw new \RuntimeException('Only small test quantities allowed.'); }
            foreach ($quote->getAllVisibleItems() as $item) {
                if (!$helper->isLoyaltyProduct($item, true)) { $item->setQty($quantity); }
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            note($command, ['quote_id' => $quote->getId(), 'quantity' => $quantity,
                'items' => array_map(static fn($i) => ['sku' => $i->getSku(), 'qty' => $i->getQty(),
                    'loyalty' => $helper->isLoyaltyProduct($i, true)], $quote->getAllVisibleItems()), 'events' => outbox()]);
            break;

        case 'theme-hyva':
        case 'theme-restore':
            if ($storeId !== 2) {
                throw new \RuntimeException('Theme acceptance tests are restricted to the second local store.');
            }
            $path = 'design/theme/theme_id';
            $writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
            if ($command === 'theme-hyva') {
                if (isset($state['theme_backup'])) {
                    throw new \RuntimeException('Theme test already started; preserve the existing backup.');
                }
                $row = $db->fetchRow($db->select()->from($resource->getTableName('core_config_data'))
                    ->where('scope = ?', 'stores')->where('scope_id = ?', $storeId)->where('path = ?', $path));
                $themeId = (int) $db->fetchOne($db->select()->from($resource->getTableName('theme'), ['theme_id'])
                    ->where('theme_path = ?', 'Hyva/default')->where('area = ?', 'frontend'));
                if (!$themeId) {
                    throw new \RuntimeException('Hyva is not installed.');
                }
                $state['theme_backup'] = ['row' => $row ?: null, 'test_theme_id' => $themeId, 'restored' => false];
                checkpoint();
                $writer->save($path, (string) $themeId, 'stores', $storeId);
            } else {
                $backup = $state['theme_backup'] ?? null;
                if (!$backup || $backup['restored']) {
                    throw new \RuntimeException('No active theme test to restore.');
                }
                $current = $db->fetchOne($db->select()->from($resource->getTableName('core_config_data'), ['value'])
                    ->where('scope = ?', 'stores')->where('scope_id = ?', $storeId)->where('path = ?', $path));
                if ((string) $current !== (string) $backup['test_theme_id']) {
                    throw new \RuntimeException('Theme was changed externally; do not overwrite it.');
                }
                if ($backup['row'] === null) {
                    $writer->delete($path, 'stores', $storeId);
                } else {
                    $writer->save($path, $backup['row']['value'], 'stores', $storeId);
                }
                $state['theme_backup']['restored'] = true;
            }
            $cache = $om->get(\Magento\Framework\App\Cache\TypeListInterface::class);
            foreach (['config', 'layout', 'block_html', 'full_page'] as $type) {
                $cache->cleanType($type);
            }
            note($command, ['store_id' => $storeId, 'backup' => $state['theme_backup']]);
            break;

        case 'expire':
            $quote = quoteForCase($case);
            $others = $db->fetchAll($db->select()
                ->from(['q' => $resource->getTableName('quote')], ['entity_id'])
                ->joinInner(['i' => $resource->getTableName('quote_item')], 'i.quote_id = q.entity_id', [])
                ->joinLeft(['o' => $resource->getTableName('quote_item_option')], "o.item_id = i.item_id AND o.code = 'loyalty_locked_qty'", [])
                ->where('q.is_active = ?', 1)->where("(i.loyalty_locked_qty = 1 OR o.value = '1')")
                ->where('q.entity_id <> ?', $quote->getId()));
            if ($others) {
                throw new \RuntimeException('Other loyalty carts exist; refusing to run a global expiry test.');
            }
            $ids = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($helper->isLoyaltyProduct($item, true)) {
                    $ids[] = (int) $item->getId();
                }
            }
            if (!$ids) {
                throw new \RuntimeException('No test loyalty item to expire.');
            }
            $db->update($resource->getTableName('quote_item'),
                ['created_at' => gmdate('Y-m-d H:i:s', time() - ($helper->getCartExpiryHours($storeId) + 1) * 3600)],
                ['item_id IN (?)' => $ids, 'quote_id = ?' => $quote->getId()]);
            $om->get(\LoyaltyEngage\LoyaltyShop\Cron\CartExpiry::class)->execute();
            note('expire:' . $case, ['expired_item_ids' => $ids, 'events' => outbox()]);
            break;

        case 'close-cart':
            $quote = quoteForCase($case);
            if ($quote->getCouponCode() || isset($state['cases'][$case]['order_id'])
                || array_filter($quote->getAllVisibleItems(), static fn($i) => $helper->isLoyaltyProduct($i, true))
                || (int) remoteStatus()['reservedCoins'] !== 0) {
                throw new \RuntimeException('Only a test cart without coupons, orders or remote reservations can be closed.');
            }
            $quote->setIsActive(false);
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            note('close-cart:' . $case, ['quote_id' => $quote->getId(), 'preserved' => true]);
            break;

        case 'review':
        case 'review-approve':
        case 'review-resave':
            $review = $om->get(\Magento\Review\Model\ReviewFactory::class)->create();
            if ($command === 'review') {
                if (isset($state['review_id'])) {
                    throw new \RuntimeException('This run already has a test review.');
                }
                $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('24-MB01', false, $storeId);
                $review->setEntityId($review->getEntityIdByCode('product'))->setEntityPkValue($product->getId())
                    ->setStatusId(\Magento\Review\Model\Review::STATUS_PENDING)->setCustomerId($customerId)
                    ->setStoreId($storeId)->setStores([$storeId])->setNickname('Automated local test')
                    ->setTitle($run . ' - TEST ONLY')->setDetail('Local integration test, not a customer product recommendation.');
            } else {
                $review->load((int) ($state['review_id'] ?? 0));
                if (!$review->getId() || (int) $review->getCustomerId() !== $customerId || !str_starts_with($review->getTitle(), $run)) {
                    throw new \RuntimeException('Not a review owned by this run.');
                }
                if ($command === 'review-approve') {
                    $review->setStatusId(\Magento\Review\Model\Review::STATUS_APPROVED);
                }
            }
            $review->save();
            $state['review_id'] = (int) $review->getId();
            note($command, ['review_id' => $review->getId(), 'status' => $review->getStatusId(), 'events' => outbox()]);
            break;

        case 'resave-refund':
            $id = $state['cases'][$case]['refunds'][$argv[3] ?? '1'] ?? 0;
            $memo = $om->get(\Magento\Sales\Api\CreditmemoRepositoryInterface::class)->get((int) $id);
            if ((int) $memo->getOrderId() !== (int) orderForCase($case)->getId()) {
                throw new \RuntimeException('Not a refund owned by this run.');
            }
            $memo->addComment($run . ' duplicate-save guard check', false, false);
            $om->get(\Magento\Sales\Api\CreditmemoRepositoryInterface::class)->save($memo);
            note($command, ['creditmemo_id' => $id, 'events' => outbox()]);
            break;

        case 'inspect':
            $quote = quoteForCase($case);
            note('inspect:' . $case, [
                'quote_id' => $quote->getId(), 'active' => $quote->getIsActive(), 'coupon' => $quote->getCouponCode(),
                'items' => array_map(static fn($i) => ['id' => $i->getId(), 'sku' => $i->getSku(), 'qty' => $i->getQty(),
                    'price' => $i->getPrice(), 'loyalty' => $helper->isLoyaltyProduct($i, true)], $quote->getAllVisibleItems()),
                'mutations' => $db->fetchAll($db->select()->from($resource->getTableName('loyaltyshop_mutation'),
                    ['operation', 'sku', 'status', 'last_error'])->where('quote_id = ?', $quote->getId())),
                'remote' => remoteStatus(), 'events' => outbox(),
            ]);
            break;

        case 'sync':
        case 'sync-unauthorized':
        case 'sync-invalid':
            $remote = remoteStatus();
            $provider = $om->get(\LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider::class);
            $before = [];
            foreach ($stores->getStores() as $otherStore) {
                $before[$otherStore->getCode()] = $provider->getCustomerLoyaltyData($customer, (int) $otherStore->getId());
            }
            $body = ['email' => $email, 'storeCode' => $store->getCode(),
                'leCurrentTier' => $remote['currentTier'] ?? '', 'lePoints' => (int) $remote['currentPoints'],
                'leAvailableCoins' => (int) $remote['availableCoins'], 'leReservedCoins' => (int) $remote['reservedCoins'],
                'leExpiringPoints30d' => (int) $remote['expiringPoints30d'],
                'leNextTier' => $remote['nextTier'] ?? '', 'lePointsToNextTier' => (int) ($remote['pointsRemainingToNextTier'] ?? 0)];
            if ($command === 'sync-invalid') {
                $body['email'] = '';
            }
            // TLS relaxation is restricted to this local Docker hostname, never the remote tenant.
            $curl = curl_init('https://app:8443/rest/' . rawurlencode($store->getCode()) . '/V1/loyalty/customer/update');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR), CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER => ['Host: magento.test', 'Content-Type: application/json', 'Accept: application/json']]);
            if ($command !== 'sync-unauthorized') {
                curl_setopt($curl, CURLOPT_USERPWD, $helper->getClientId($storeId) . ':' . $helper->getClientSecret($storeId));
            }
            $response = curl_exec($curl);
            $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $response = json_decode((string) $response, true);
            if (is_array($response)) {
                unset($response['trace']);
            }
            $after = [];
            foreach ($stores->getStores() as $otherStore) {
                $after[$otherStore->getCode()] = $provider->getCustomerLoyaltyData($customer, (int) $otherStore->getId());
            }
            note($command, ['http_status' => $http, 'response' => $response,
                'before' => $before, 'after' => $after]);
            break;

        case 'status':
            $remote = remoteStatus();
            $local = $om->get(\LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider::class)
                ->getCustomerLoyaltyData($customer, $storeId);
            if (!isset($state['baseline'])) {
                $state['baseline'] = ['remote' => $remote, 'local' => $local];
            }
            note('status', ['store_id' => $storeId, 'remote' => $remote, 'local' => $local, 'events' => outbox()]);
            break;

        case 'quote':
            if (isset($state['cases'][$case]['quote_id'])) {
                throw new \RuntimeException('Case already has a quote; do not create a duplicate.');
            }
            $active = $db->fetchAll($db->select()->from($resource->getTableName('quote'), ['entity_id', 'items_count'])
                ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId)->where('is_active = ?', 1));
            if (count($active) > 1) {
                throw new \RuntimeException('Multiple active carts exist; inspect before continuing.');
            }
            $sku = $argv[3] ?? '24-MB01';
            $qty = (float) ($argv[4] ?? 2);
            if ($qty <= 0 || $qty > 3) {
                throw new \RuntimeException('Only small test quantities are permitted.');
            }
            $quote = $active
                ? $om->get(\Magento\Quote\Api\CartRepositoryInterfaceFactory::class)->create()->getActive((int) $active[0]['entity_id'])
                : $om->get(\Magento\Quote\Model\QuoteFactory::class)->create()->setStore($store)
                    ->assignCustomer($customer)->setCustomerIsGuest(false)->setIsActive(true);
            if ($quote->getAllVisibleItems() || $quote->getCouponCode()) {
                throw new \RuntimeException('The existing customer cart is not empty; preserving its contents.');
            }
            $address = ['firstname' => 'Loyalty', 'lastname' => 'Acceptance Test', 'street' => ['Teststraat 1'],
                'city' => 'Amsterdam', 'postcode' => '1012AB', 'country_id' => 'NL', 'telephone' => '0000000000',
                'save_in_address_book' => 0];
            $quote->getBillingAddress()->addData($address);
            $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->setShippingMethod('flatrate_flatrate');
            $quote->getPayment()->setMethod('checkmo');
            $quote->setCustomerNote($run . ' ' . $case . ' - OFFLINE TEST - DO NOT SHIP')->setCustomerNoteNotify(false);
            $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($sku, false, $storeId);
            $item = $quote->addProduct(clone $product, new \Magento\Framework\DataObject(['qty' => $qty]));
            if (is_string($item)) {
                throw new \RuntimeException($item);
            }
            $quote->collectTotals();
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            $state['cases'][$case] = ['quote_id' => (int) $quote->getId(), 'store_id' => $storeId];
            note('quote:' . $case, ['quote_id' => (int) $quote->getId(), 'sku' => $sku, 'qty' => $qty,
                'subtotal' => $quote->getSubtotal(), 'total' => $quote->getGrandTotal(), 'currency' => $quote->getQuoteCurrencyCode()]);
            break;

        case 'order':
            if (isset($state['cases'][$case]['order_id'])) {
                throw new \RuntimeException('Order already created; do not repeat placement.');
            }
            $quote = quoteForCase($case);
            $increment = $run . '-' . strtoupper($case);
            if ($db->fetchOne($db->select()->from($resource->getTableName('sales_order'), ['entity_id'])->where('increment_id = ?', $increment))) {
                throw new \RuntimeException('Order already exists; reconcile the state file first.');
            }
            $quote->setReservedOrderId($increment)->setInventoryProcessed(false);
            $quote->getPayment()->setMethod('checkmo');
            $quote->getShippingAddress()->setShippingMethod('flatrate_flatrate')->setCollectShippingRates(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
            $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
            if (!$order) {
                throw new \RuntimeException('No order created.');
            }
            $state['cases'][$case]['order_id'] = (int) $order->getId();
            $state['cases'][$case]['increment_id'] = $order->getIncrementId();
            $order->addCommentToStatusHistory($run . ' ' . $case . ' - OFFLINE TEST - DO NOT SHIP')->setIsCustomerNotified(false);
            $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
            note('order:' . $case, ['id' => (int) $order->getId(), 'increment_id' => $order->getIncrementId(),
                'status' => $order->getStatus(), 'source' => $order->getData('loyalty_order_source'), 'total' => $order->getGrandTotal(), 'events' => outbox()]);
            break;

        case 'invoice':
            $order = orderForCase($case);
            if ($order->getPayment()->getMethod() !== 'checkmo' || !$order->canInvoice()) {
                throw new \RuntimeException('Only an uninvoiced checkmo test order can be invoiced here.');
            }
            $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
            $order->setIsInProcess(true);
            $om->get(\Magento\Framework\DB\TransactionFactory::class)->create()->addObject($invoice)->addObject($order)->save();
            $state['cases'][$case]['invoice_id'] = (int) $invoice->getId();
            note('invoice:' . $case, ['invoice_id' => (int) $invoice->getId(), 'status' => $order->getStatus(), 'events' => outbox()]);
            break;

        case 'refund':
            $part = $argv[3] ?? '1';
            if (isset($state['cases'][$case]['refunds'][$part])) {
                throw new \RuntimeException('This partial refund was already created.');
            }
            $order = orderForCase($case);
            if ($order->getPayment()->getMethod() !== 'checkmo') {
                throw new \RuntimeException('Only offline test payments may be refunded.');
            }
            $qtys = [];
            foreach ($order->getAllItems() as $item) {
                $qtys[$item->getId()] = $item->getParentItemId() ? 0 : min(1, (float) $item->getQtyToRefund());
            }
            $memo = $om->get(\Magento\Sales\Model\Order\CreditmemoFactory::class)->createByOrder($order,
                ['qtys' => $qtys, 'shipping_amount' => 0]);
            $memo->addComment($run . ' partial refund ' . $part, false, false);
            $om->get(\Magento\Sales\Api\CreditmemoManagementInterface::class)->refund($memo, true);
            $state['cases'][$case]['refunds'][$part] = (int) $memo->getId();
            note('refund:' . $case . ':' . $part, ['creditmemo_id' => (int) $memo->getId(),
                'number' => $memo->getIncrementId(), 'amount' => $memo->getGrandTotal(), 'events' => outbox()]);
            break;

        case 'resave':
            $order = orderForCase($case);
            $order->addCommentToStatusHistory($run . ' duplicate-save guard check')->setIsCustomerNotified(false);
            $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
            note('resave:' . $case, ['events' => outbox()]);
            break;

        case 'deliver':
            $pending = $db->fetchAll($db->select()->from($resource->getTableName('loyaltyshop_outbox'))->where('status = ?', 'pending'));
            foreach ($pending as $row) {
                $p = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $identifier = $p['identifier'] ?? $p['email'] ?? $p['customer_email'] ?? '';
                if ((int) $row['entity_id'] <= $state['outbox_start'] || !in_array($identifier, [$email, $helper->hashEmail($email)], true)) {
                    throw new \RuntimeException('Unrelated pending events exist; refusing to drain the entire outbox.');
                }
            }
            $om->get(\LoyaltyEngage\LoyaltyShop\Cron\DeliverEvents::class)->execute();
            note('deliver', ['events' => outbox(), 'remote' => remoteStatus()]);
            break;

        case 'add':
        case 'remove':
        case 'remove-all':
        case 'coupon':
            quoteForCase($case);
            $sku = $argv[3] ?? '24-MB01';
            if ($command === 'add' || $command === 'coupon') {
                $cart = $om->get(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart::class);
                $result = $command === 'add' ? $cart->addProduct($customerId, $sku) : $cart->buyDiscountCodeProduct($customerId, $sku);
            } else {
                $result = $om->get(\LoyaltyEngage\LoyaltyShop\Model\CartRemoval::class)
                    ->remove($customerId, $command === 'remove' ? $sku : null);
            }
            $quote = quoteForCase($case);
            $transport = $om->get(\Magento\Framework\HTTP\Client\Curl::class);
            $transportBody = json_decode((string) $transport->getBody(), true);
            $apiError = !$result->getSuccess() ? ['http_status' => $transport->getStatus(),
                'response' => is_array($transportBody) ? $transportBody : 'Non-JSON or no response'] : null;
            note($command . ':' . $case, ['success' => $result->getSuccess(), 'message' => $result->getMessage(),
                'items' => array_map(static fn($i) => ['id' => $i->getId(), 'sku' => $i->getSku(), 'qty' => $i->getQty(),
                    'price' => $i->getPrice(), 'loyalty' => $helper->isLoyaltyProduct($i, true)], $quote->getAllVisibleItems()),
                'coupon' => $quote->getCouponCode(), 'total' => $quote->getGrandTotal(), 'api_error' => $apiError,
                'remote' => remoteStatus()]);
            if (!$result->getSuccess()) {
                exit(2);
            }
            break;

        default:
            throw new \InvalidArgumentException('Unknown command.');
    }
} catch (\Throwable $e) {
    note('ERROR:' . $command, ['type' => get_class($e), 'message' => $e->getMessage()]);
    exit(1);
}
