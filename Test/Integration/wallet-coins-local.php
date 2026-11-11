<?php
declare(strict_types=1);

// Local-only native checkout tests. The transport is fake; no real coins/PSP calls.
use LoyaltyEngage\LoyaltyShop\Model\Wallet\{Config, Funding, DiscountPurchase, Ledger, Selection, Tender, Policy, Guard};
use LoyaltyEngage\LoyaltyShop\Model\{MutationJournal, EventOutbox};
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\{ApiClient, ApiRejectionException};
use Magento\Framework\App\{Bootstrap, ResourceConnection};
use Magento\Framework\App\Config\ScopeConfigInterface;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') { throw new RuntimeException('Local opt-in required.'); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
if (parse_url((string) $om->get(ScopeConfigInterface::class)->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to magento.test.');
}
$code = getenv('LOYALTY_WALLET_TEST_STORE') ?: 'default';
if (!in_array($code, ['default', 'hyva'], true)) { throw new RuntimeException('Local stores only.'); }
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $stores->getStore($code);
$stores->setCurrentStore($store);
$storeId = (int) $store->getId();
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$scope = $om->get(\Magento\Framework\App\MutableScopeConfig::class);
foreach ([
    'loyalty/general/module_enable' => 1, 'loyalty/wallet/enabled' => 1,
    'loyalty/wallet/spending_enabled' => 1, 'loyalty/wallet/coins_required' => 1,
    'loyalty/wallet/payment_methods' => 'free,checkmo,banktransfer',
    'loyalty/export/purchase_order_status' => 'wallet_test_never',
    'loyalty/wallet/redemption_sku_1' => 'WALLET_1', 'loyalty/wallet/redemption_sku_5' => 'WALLET_5',
    'loyalty/wallet/redemption_sku_10' => 'WALLET_10', 'loyalty/wallet/redemption_sku_50' => 'WALLET_50',
    'payment/checkmo/active' => 1, 'payment/free/active' => 1,
    'carriers/flatrate/active' => 1, 'carriers/flatrate/price' => '5', 'carriers/flatrate/type' => 'O',
] as $path => $value) {
    $scope->setValue($path, $value);
    foreach ([$storeId, $code, null] as $key) { $scope->setValue($path, $value, 'store', $key); }
}
$config = $om->get(Config::class);
(new ReflectionProperty(Config::class, 'scopeConfig'))->setValue($config, $scope);
$ledger = $om->get(Ledger::class);
$selection = $om->get(Selection::class);
$tender = $om->get(Tender::class);
$api = new class extends ApiClient {
    public array $calls = [];
    public ?string $failure = null;
    public function __construct() {}
    public function post(string $url, array $body = [], ?int $storeId = null): array {
        $this->calls[] = [$url, $body, $storeId];
        if (count($this->calls) === 2 || $this->failure === 'reject') { throw new ApiRejectionException('AVAILABLE_COINS_INSUFFICIENT'); }
        if ($this->failure === 'timeout') { throw new RuntimeException('Fixture transport timeout'); }
        return ['discountCode' => $body['discountCode'], 'spentCoins' => 100];
    }
};
$helper = new class extends Data {
    public function __construct() {}
    public function getApiUrl(?int $storeId = null): ?string { return 'https://wallet-fixture.invalid'; }
};
$outbox = new class($resource) extends EventOutbox {
    public array $keys = [];
    public function __construct(private ResourceConnection $resource) { parent::__construct($resource); }
    public function publish(string $topic, string $json): void {
        parent::publish($topic, $json);
        $key = self::eventKey($topic, json_decode($json, true));
        $this->keys[] = $key;
        // Still inside Funding's transaction: never expose a dispatchable test event.
        $this->resource->getConnection()->update($this->table(), ['status' => 'failed', 'last_error' => 'Local fixture, no transport'], ['event_key = ?' => $key]);
    }
};
$journal = $om->get(MutationJournal::class);
$funding = new Funding($resource, $ledger, $selection, $config, $om->get(Policy::class),
    new DiscountPurchase($journal, $api, $helper, $resource), $journal, $outbox,
    $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class));
$submitPlugin = $om->get(\LoyaltyEngage\LoyaltyShop\Plugin\Wallet\Submit::class);
(new ReflectionProperty($submitPlugin, 'funding'))->setValue($submitPlugin, $funding);
$customerId = 0;
$quoteIds = [];
$checks = 0;
$check = static function ($expected, $actual, string $message) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . json_encode([$expected, $actual])); }
};
$balance = static function () use ($db, $ledger, &$customerId, $storeId): array {
    return $db->fetchRow($db->select()->from($ledger->table('balance'))
        ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
};
try {
    $db->insert($resource->getTableName('customer_entity'), ['website_id' => (int) $store->getWebsiteId(),
        'store_id' => $storeId, 'email' => 'wallet-coins-' . bin2hex(random_bytes(8)) . '@example.com',
        'firstname' => 'Wallet', 'lastname' => 'Coin Fixture']);
    $customerId = (int) $db->lastInsertId();
    $customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->getById($customerId);
    $db->insert($ledger->table('balance'), ['customer_id' => $customerId, 'store_id' => $storeId, 'balance_cents' => 10000]);
    $make = static function (int $amount) use ($om, $store, $customer, $selection, &$quoteIds) {
        $quote = $om->create(\Magento\Quote\Model\Quote::class)->setStore($store)->assignCustomer($customer)
            ->setIsActive(true)->setIsMultiShipping(false)->setCustomerIsGuest(false)->setCheckoutMethod('customer');
        $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById(1, false, (int) $store->getId());
        $quote->addProduct($product, 2);
        $address = ['firstname' => 'Wallet', 'lastname' => 'Fixture', 'street' => ['Teststraat 1'],
            'city' => 'Teststad', 'postcode' => '1000AA', 'country_id' => 'NL', 'telephone' => '0612345678',
            'email' => $customer->getEmail(), 'save_in_address_book' => 0];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setSameAsBilling(true)->setCollectShippingRates(true)
            ->collectShippingRates()->setShippingMethod('flatrate_flatrate');
        $quote->getPayment()->setMethod('checkmo');
        $quote->collectTotals();
        $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
        $quoteIds[] = (int) $quote->getId();
        $selection->choose($quote, $amount);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        return $quote;
    };
    $management = $om->get(\Magento\Quote\Model\QuoteManagement::class);
    $quote = $make(1650);
    $check(1600, (int) $quote->getData(Selection::AMOUNT), 'Whole euro selection');
    try { $management->submit($quote); throw new RuntimeException('Expected second voucher rejection'); }
    catch (\Magento\Framework\Exception\LocalizedException $e) {
        $check(true, str_contains($e->getMessage(), 'enough available coins'), 'Explicit refusal has a customer-facing message');
    }
    $check(2, count($api->calls), 'Only attempted two vouchers');
    $check(10000, (int) $balance()['balance_cents'], 'Failed checkout never debits local wallet');
    $check(1000, (int) $balance()['funded_cents'], 'First purchase preserved');
    $check(0, (int) $balance()['reserved_funded'], 'Failed attempt releases funded reservation');
    $check(0, (int) $balance()['reserved_cents'], 'Failed attempt releases wallet reservation');
    $submit = $om->get(\LoyaltyEngage\LoyaltyShop\Plugin\Wallet\Submit::class);
    try {
        $submit->aroundSubmit($management, static function () { throw new RuntimeException('Fixture payment failure'); }, $quote);
        throw new RuntimeException('Expected payment failure');
    } catch (RuntimeException $e) { $check('Fixture payment failure', $e->getMessage(), 'Payment failed after successful purchases'); }
    $check(4, count($api->calls), 'Retry buys only missing five and one euro');
    $check(['WALLET_10', 'WALLET_5', 'WALLET_5', 'WALLET_1'], array_column(array_column($api->calls, 1), 'sku'), 'Denomination plan');
    $check(1600, (int) $balance()['funded_cents'], 'Payment failure preserves all purchased backing');
    $check(10000, (int) $balance()['balance_cents'], 'Backing does not mint extra euros');
    $order = $management->submit($quote);
    $check(4, count($api->calls), 'Successful retry does not spend coins twice');
    $check(8400, (int) $balance()['balance_cents'], 'Native order debits wallet once');
    $check(0, (int) $balance()['funded_cents'], 'Native order consumes backing');
    $check(1600, (int) $tender->get($order)['funded_spent'], 'Historical funded allocation persisted');
    $order->cancel();
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
    $check(10000, (int) $balance()['balance_cents'], 'Cancellation restores unused wallet once');
    $check(1600, (int) $balance()['funded_cents'], 'Cancellation preserves purchased backing once');
    $order2 = $management->submit($make(1600));
    $check(4, count($api->calls), 'New cart reuses canceled purchase');
    $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)->prepareInvoice($order2);
    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
    $om->create(\Magento\Framework\DB\Transaction::class)->addObject($invoice)->addObject($order2)->save();
    $memo = $om->get(\Magento\Sales\Model\Order\CreditmemoFactory::class)->createByInvoice($invoice,
        ['shipping_amount' => $order2->getBaseShippingAmount()]);
    $om->get(\Magento\Sales\Model\Service\CreditmemoService::class)->refund($memo, true);
    $check(true, $memo->getId() > 0, 'Native paid invoice/refund services succeeded');
    $check(8400, (int) $balance()['balance_cents'], 'Return forfeits spent wallet');
    $check(0, (int) $balance()['funded_cents'], 'Return does not restore purchased backing');
    $api->failure = 'timeout';
    $uncertain = $make(1000);
    try { $management->submit($uncertain); throw new RuntimeException('Expected timeout'); }
    catch (RuntimeException $e) { $check('Fixture transport timeout', $e->getMessage(), 'Unknown API outcome'); }
    $calls = count($api->calls);
    try { $management->submit($uncertain); throw new RuntimeException('Expected reconciliation'); }
    catch (\Magento\Framework\Exception\LocalizedException $e) {
        $check(true, str_contains($e->getMessage(), 'reconciliation'), 'No blind timeout retry');
    }
    $check($calls, count($api->calls), 'Unknown outcome did not call API twice');
    $selection->forget($uncertain);
    $cashOrder = $management->submit($uncertain);
    $check([], $tender->get($cashOrder), 'Ordinary ordering remains possible');
    $check(8400, (int) $balance()['balance_cents'], 'Cash order does not touch wallet');
    $check(3, count(array_unique($outbox->keys)), 'Each confirmed voucher has a distinct durable redeem event');
    // Simulate a confirmed response persisted just before a process crash.
    // Never use this fixture-only write as a real reconciliation shortcut.
    $last = $api->calls[array_key_last($api->calls)][1];
    $key = substr($last['discountCode'], strlen('LE-WALLET-'));
    $db->update($resource->getTableName('loyaltyshop_mutation'), ['status' => 'received',
        'response' => json_encode(['discountCode' => $last['discountCode'], 'spentCoins' => 100])], ['operation_key = ?' => $key]);
    $recoveredOrder = $management->submit($make(500));
    $check($calls, count($api->calls), 'Confirmed response recovery makes no API request');
    $check(500, (int) $balance()['funded_cents'], 'Recovered larger voucher leaves funded remainder');
    $check(7900, (int) $balance()['balance_cents'], 'Recovery does not double-credit wallet');
    $check(4, count(array_unique($outbox->keys)), 'Recovered voucher has its own redeem event');
    foreach ([$storeId, $code, null] as $key) { $scope->setValue('loyalty/wallet/redemption_sku_1', '', 'store', $key); }
    $invalid = $make(1000);
    try { $management->submit($invalid); throw new RuntimeException('Expected invalid mapping refusal'); }
    catch (\Magento\Framework\Exception\LocalizedException $e) {
        $check(true, str_contains($e->getMessage(), 'all four'), 'Incomplete mappings fail closed');
    }
    $check($calls, count($api->calls), 'Invalid mapping makes no API call');
    foreach ([$storeId, $code, null] as $key) { $scope->setValue('loyalty/wallet/coins_required', 0, 'store', $key); }
    $uncoupled = $management->submit($invalid);
    $check($calls, count($api->calls), 'Disabled coupling makes no API call');
    $check(500, (int) $tender->get($uncoupled)['funded_spent'], 'Disabling coupling still accounts for existing backing');
    $check(0, (int) $balance()['funded_cents'], 'Backing cannot be reused after an uncoupled order');
    $uncoupled->cancel();
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($uncoupled);
    $check(500, (int) $balance()['funded_cents'], 'Mixed backed/unbacked cancellation restores only original backing');
    $otherStore = (int) $stores->getStore($code === 'default' ? 'hyva' : 'default')->getId();
    $check(0, $ledger->available($customerId, $otherStore), 'No cross-store wallet value');
    echo json_encode(['store' => $code, 'wallet_coin_checks' => $checks, 'all_passed' => true, 'transport' => 'fake']) . "\n";
} finally {
    $om->get(Guard::class)->release();
    if ($customerId) {
        $orders = $db->fetchCol($db->select()->from($resource->getTableName('sales_order'), ['entity_id'])->where('customer_id = ?', $customerId));
        if ($orders) {
            foreach (['invoice', 'creditmemo'] as $kind) {
                $ids = $db->fetchCol($db->select()->from($resource->getTableName('sales_' . $kind), ['entity_id'])
                    ->where('order_id IN (?)', $orders));
                if ($ids) { $db->delete($resource->getTableName('sales_' . $kind . '_grid'), ['entity_id IN (?)' => $ids]); }
            }
            $db->delete($ledger->table('document'), ['order_id IN (?)' => $orders]);
            $db->delete($resource->getTableName('sales_order'), ['entity_id IN (?)' => $orders]);
            $db->delete($resource->getTableName('sales_order_grid'), ['entity_id IN (?)' => $orders]);
        }
        foreach (['funding', 'ledger', 'quote'] as $name) { $db->delete($ledger->table($name), ['customer_id = ?' => $customerId]); }
        $db->delete($resource->getTableName('loyaltyshop_mutation'), ['customer_id = ?' => $customerId]);
        if ($quoteIds) { $db->delete($resource->getTableName('quote'), ['entity_id IN (?)' => $quoteIds]); }
        if ($outbox->keys) { $db->delete($outbox->table(), ['event_key IN (?)' => $outbox->keys]); }
        $db->delete($resource->getTableName('customer_entity'), ['entity_id = ?' => $customerId]);
    }
    echo "Local coin fixtures removed.\n";
}
