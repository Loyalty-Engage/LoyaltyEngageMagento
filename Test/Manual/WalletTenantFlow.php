<?php
declare(strict_types=1);

// Explicit live-tenant acceptance only. Real coins are spent; retain all audit state.
use LoyaltyEngage\LoyaltyShop\Model\Wallet\{Config, Funding, DiscountPurchase, Ledger, Selection, Policy};
use LoyaltyEngage\LoyaltyShop\Model\{MutationJournal, EventOutbox};
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use Magento\Framework\App\{Bootstrap, ResourceConnection};

if (getenv('LE_LIVE_WALLET_APPROVED') !== '1' || PHP_SAPI !== 'cli'
    || !filter_var(getenv('LE_TEST_EMAIL'), FILTER_VALIDATE_EMAIL)) {
    exit("Explicit live test opt-in and LE_TEST_EMAIL required.\n");
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
if (parse_url($stores->getStore('default')->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Local Magento only.');
}
$mode = $argv[1] ?? 'status';
$retry = $mode === 'retry10';
if ($retry) { $mode = '10'; }
if (!in_array($mode, ['10', '30', 'status', 'deliver'], true)) { throw new RuntimeException('Use 10, 30, status, deliver or explicitly approved retry10.'); }
$file = BP . '/var/WALLET-LIVE-20261110.json';
$lock = fopen($file . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Test already running.'); }
chmod($file . '.lock', 0600);
$state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : ['cases' => []];
$save = static function () use (&$state, $file): void {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($file, 0600);
};
$email = (string) getenv('LE_TEST_EMAIL');
$sku = 'VOUCHER-EUR-10-00';
$helper = $om->get(Data::class);
$api = $om->get(ApiClient::class);
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$ledger = $om->get(Ledger::class);
$selection = $om->get(Selection::class);
$status = static fn (int $storeId): array => $api->get(rtrim((string) $helper->getApiUrl($storeId), '/')
    . '/api/v1/contact/' . $helper->hashEmail($email) . '/loyalty_status', [], $storeId);
if ($mode === 'status') {
    echo json_encode(['remote' => $status(1), 'cases' => $state['cases']], JSON_PRETTY_PRINT) . "\n";
    exit;
}
if ($mode === 'deliver') {
    $outbox = $om->get(EventOutbox::class);
    $dispatcher = $om->get(\LoyaltyEngage\LoyaltyShop\Cron\DeliverEvents::class);
    $dispatch = new ReflectionMethod($dispatcher, 'deliver');
    $queueLock = $om->get(\Magento\Framework\Lock\LockManagerInterface::class);
    if (!$queueLock->lock('loyaltyshop_outbox', 0)) { throw new RuntimeException('Outbox busy.'); }
    try {
        foreach ($state['cases'] as $case) {
            if (empty($case['quote_id'])) { continue; }
            $intents = $db->fetchCol($db->select()->from($ledger->table('funding'), ['intent'])
                ->where('quote_id = ?', $case['quote_id'])->where('customer_id = ?', $case['customer_id']));
            foreach ($intents as $intent) {
                $fund = $db->fetchRow($db->select()->from($ledger->table('funding'))->where('intent = ?', $intent));
                if ($fund['status'] !== 'credited') { continue; }
                $event = $outbox->find('loyaltyshop.redeem_discount_event', ['store_id' => (int) $fund['store_id'],
                    'identifier' => $fund['identifier'], 'discount_code' => $fund['discount_code'], 'wallet_intent' => $intent]);
                if ($event && $event['status'] === 'pending' && (int) $event['attempts'] === 0) {
                    $dispatch->invoke($dispatcher, $event);
                }
                echo json_encode($db->fetchRow($db->select()->from($outbox->table(),
                    ['entity_id', 'status', 'attempts', 'last_error'])->where('entity_id = ?', $event['entity_id']))) . "\n";
            }
        }
    } finally { $queueLock->unlock('loyaltyshop_outbox'); }
    exit;
}
if (isset($state['cases'][$mode]) && !$retry) { throw new RuntimeException('Case already attempted. Inspect durable state; never blindly rerun.'); }
$store = $stores->getStore($mode === '10' ? 'default' : 'hyva');
$storeId = (int) $store->getId();
$stores->setCurrentStore($store);
$before = $status($storeId);
if ($retry) {
    $prior = $state['cases']['10'] ?? [];
    if (isset($state['retry_approved']) || ($prior['error'] ?? '') !== 'Loyalty Engage returned HTTP 400'
        || isset($prior['order_id']) || ($prior['before']['availableCoins'] ?? null) !== $before['availableCoins']) {
        throw new RuntimeException('Prior failure or balance does not match the approved one-time retry.');
    }
    $pending = $db->fetchAll($db->select()->from($ledger->table('funding'))->where('quote_id = ?', $prior['quote_id']));
    if (count($pending) !== 1 || $pending[0]['status'] !== 'pending') { throw new RuntimeException('Unexpected prior funding state.'); }
    $p = $pending[0];
    $key = hash('sha256', 'wallet-voucher:' . $p['store_id'] . ':' . $p['customer_id'] . ':' . $p['quote_id'] . ':' . $p['intent'] . ':1');
    try {
        $api->get(rtrim($helper->getApiUrl($storeId), '/') . '/api/v1/discount/LE-WALLET-' . $key, [], $storeId);
        throw new RuntimeException('Prior code exists; do not buy again.');
    } catch (\LoyaltyEngage\LoyaltyShop\Service\ApiException $e) {
        if ($e->getCode() !== 404) { throw $e; }
    }
    $state['retry_approved'] = ['at' => gmdate(DATE_ATOM), 'prior' => $prior,
        'basis' => 'User explicitly requested one retry after HTTP 400, unchanged balance and repeated coupon GET 404. Original mutation retained.'];
    $save();
    $db->update($ledger->table('funding'), ['status' => 'operator_retry'], ['intent = ?' => $p['intent'], 'status = ?' => 'pending']);
}
$amount = (int) $mode * 100;
// Isolated process configuration: unused denominations cannot pass the live transport guard.
// No fake SKU is saved to merchant configuration and no non-EUR10 purchase is authorized.
$scope = $om->get(\Magento\Framework\App\MutableScopeConfig::class);
foreach ([
    'loyalty/general/module_enable' => 1, 'loyalty/wallet/enabled' => 1,
    'loyalty/wallet/spending_enabled' => 1, 'loyalty/wallet/coins_required' => 1,
    'loyalty/wallet/payment_methods' => 'checkmo', 'payment/checkmo/active' => 1,
    'loyalty/wallet/redemption_sku_1' => 'NOT_USED_WALLET_1', 'loyalty/wallet/redemption_sku_5' => 'NOT_USED_WALLET_5',
    'loyalty/wallet/redemption_sku_10' => $sku, 'loyalty/wallet/redemption_sku_50' => 'NOT_USED_WALLET_50',
    'loyalty/export/purchase_order_status' => 'wallet_test_never',
    'sales_email/order/enabled' => 0, 'sales_email/invoice/enabled' => 0,
    'carriers/flatrate/active' => 1, 'carriers/flatrate/price' => 5, 'carriers/flatrate/type' => 'O',
] as $path => $value) {
    $scope->setValue($path, $value);
    foreach ([$storeId, $store->getCode(), null] as $key) { $scope->setValue($path, $value, 'store', $key); }
}
$config = $om->get(Config::class);
(new ReflectionProperty(Config::class, 'scopeConfig'))->setValue($config, $scope);
(new ReflectionProperty(Data::class, 'scopeConfig'))->setValue($helper, $scope);
$guardedApi = new class($api, $sku, (int) $mode / 10) extends ApiClient {
    public int $calls = 0;
    public array $failure = [];
    public function __construct(private ApiClient $live, private string $sku, private float $limit) {}
    public function setIdempotencyKey(?string $key): void { $this->live->setIdempotencyKey($key); }
    public function post(string $url, array $body = [], ?int $storeId = null): array {
        if (($body['sku'] ?? '') !== $this->sku || !str_ends_with($url, '/cart/buy_discount_code')
            || ++$this->calls > $this->limit) { throw new RuntimeException('Outside authorized live purchase scope.'); }
        try { return $this->live->post($url, $body, $storeId); }
        catch (Throwable $e) {
            $curl = (new ReflectionProperty(ApiClient::class, 'curl'))->getValue($this->live);
            $data = json_decode($curl->getBody(), true);
            $this->failure = ['http_status' => $curl->getStatus(),
                'message' => is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null,
                'response_keys' => is_array($data) ? array_keys($data) : []];
            throw $e;
        }
    }
};
$customers = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
try { $customer = $customers->get($email, (int) $store->getWebsiteId()); }
catch (\Magento\Framework\Exception\NoSuchEntityException) {
    // Local-only identity, no registration email or remote signup event.
    $db->insert($resource->getTableName('customer_entity'), ['website_id' => (int) $store->getWebsiteId(),
        'store_id' => $storeId, 'email' => $email, 'firstname' => 'Wallet', 'lastname' => 'Loyalty Test']);
    $customer = $customers->getById((int) $db->lastInsertId());
}
$customerId = (int) $customer->getId();
$existingBalance = $db->fetchRow($db->select()->from($ledger->table('balance'))
    ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
if ($existingBalance && !$retry) {
    throw new RuntimeException('Refusing to overwrite an existing wallet balance.');
}
if ($retry && (!$existingBalance || (int) $existingBalance['balance_cents'] !== 1000
    || (int) $existingBalance['reserved_cents'] !== 0 || (int) $existingBalance['funded_cents'] !== 0)) {
    throw new RuntimeException('Existing wallet differs from approved retry.');
}
$state['cases'][$mode] = ['store' => $store->getCode(), 'customer_id' => $customerId,
    'before' => $before, 'seeded_test_cents' => $amount, 'started_at' => gmdate(DATE_ATOM)];
$save();
if (!$retry) { $db->insert($ledger->table('balance'), ['customer_id' => $customerId, 'store_id' => $storeId, 'balance_cents' => $amount]); }
$funding = new Funding($resource, $ledger, $selection, $config, $om->get(Policy::class),
    new DiscountPurchase($om->get(MutationJournal::class), $guardedApi, $helper, $resource),
    $om->get(MutationJournal::class), $om->get(EventOutbox::class), $customers);
$submit = $om->get(\LoyaltyEngage\LoyaltyShop\Plugin\Wallet\Submit::class);
(new ReflectionProperty($submit, 'funding'))->setValue($submit, $funding);
try {
    $quote = $om->create(\Magento\Quote\Model\Quote::class)->setStore($store)->assignCustomer($customer)
        ->setIsActive(true)->setCustomerIsGuest(false)->setIsMultiShipping(false)->setCheckoutMethod('customer');
    $quote->addProduct($om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById(1, false, $storeId), 2);
    $address = ['firstname' => 'Wallet', 'lastname' => 'Loyalty Test', 'street' => ['Local test 1'],
        'city' => 'Teststad', 'postcode' => '1000AA', 'country_id' => 'NL', 'telephone' => '0612345678',
        'email' => $email, 'save_in_address_book' => 0];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setSameAsBilling(true)->setCollectShippingRates(true)
        ->collectShippingRates()->setShippingMethod('flatrate_flatrate');
    $quote->getPayment()->setMethod('checkmo');
    $quote->collectTotals();
    $quote->setReservedOrderId('LE-WALLET-20261110-' . $mode);
    $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
    $state['cases'][$mode]['quote_id'] = (int) $quote->getId();
    $save();
    $selection->choose($quote, $amount);
    $quote->setTotalsCollectedFlag(false)->collectTotals();
    $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
    $state['cases'][$mode]['order_id'] = (int) $order->getId();
    $state['cases'][$mode]['order_number'] = $order->getIncrementId();
    $state['cases'][$mode]['cash_remainder'] = $order->getBaseGrandTotal();
    $save();
} catch (Throwable $e) {
    $state['cases'][$mode]['error'] = $e->getMessage();
    $save();
} finally {
    $state['cases'][$mode]['api_purchase_calls'] = $guardedApi->calls;
    $state['cases'][$mode]['api_failure'] = $guardedApi->failure;
    $state['cases'][$mode]['wallet'] = $ledger->summary($customerId, $storeId);
    $state['cases'][$mode]['funding'] = $db->fetchAll($db->select()->from($ledger->table('funding'),
        ['intent', 'sku', 'amount_cents', 'status', 'spent_coins'])
        ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
    $save();
    $state['cases'][$mode]['after'] = $status($storeId);
    $save();
    echo json_encode($state['cases'][$mode], JSON_PRETTY_PRINT) . "\n";
}
