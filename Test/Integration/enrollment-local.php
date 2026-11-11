<?php
declare(strict_types=1);
// Local rollback-only fixtures. Never invokes a remote API or the whole outbox cron.
if (getenv('LE_ENROLLMENT_LOCAL_TEST') !== '1') { exit("Local opt-in required.\n"); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $stores->getStore('default');
if (parse_url($store->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') { throw new RuntimeException('Local demo only.'); }
$stores->setCurrentStore($store);
class LocalEnrollmentConfig extends \LoyaltyEngage\LoyaltyShop\Model\Enrollment\Config
{
    public string $testMode = 'required';
    public function __construct() {}
    public function mode(int $storeId): string { return $this->testMode; }
    public function label(int $storeId): string { return 'Local enrollment fixture'; }
}
class LocalEnrollmentApi extends \LoyaltyEngage\LoyaltyShop\Service\ApiClient
{
    public array $calls = [];
    public function __construct() {}
    public function post(string $url, array $body = [], ?int $storeId = null): array
    { $this->calls[] = [$url, $body, $storeId]; return ['acceptedEventCount' => 1]; }
}
$config = new LocalEnrollmentConfig();
$shared = new ReflectionProperty(\Magento\Framework\ObjectManager\ObjectManager::class, '_sharedInstances');
$instances = $shared->getValue($om);
$instances[\LoyaltyEngage\LoyaltyShop\Model\Enrollment\Config::class] = $config;
$shared->setValue($om, $instances);
$r = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $r->getConnection();
$outbox = $om->get(\LoyaltyEngage\LoyaltyShop\Model\EventOutbox::class);
$choice = $om->get(\LoyaltyEngage\LoyaltyShop\Model\Enrollment\Choice::class);
$member = $om->get(\LoyaltyEngage\LoyaltyShop\Model\Enrollment\Membership::class);
$checks = 0;
$check = static function ($expected, $actual, string $message) use (&$checks) {
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($message . ': ' . json_encode([$expected, $actual])); }
};
$db->beginTransaction();
try {
    $email = 'enrollment-' . bin2hex(random_bytes(6)) . '@example.com';
    $db->insert($r->getTableName('customer_entity'), ['email' => $email, 'website_id' => $store->getWebsiteId(),
        'store_id' => $store->getId(), 'firstname' => 'Enrollment', 'lastname' => 'Fixture']);
    $customerId = (int) $db->lastInsertId();
    $customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->getById($customerId);
    $storeId = (int) $store->getId();
    $check(false, $member->allowsWallet($customerId, $storeId), 'Empty fields cannot reveal wallet');
    $db->insert($r->getTableName('loyaltyshop_customer_store_data'), ['customer_id' => $customerId, 'store_id' => 2, 'le_points' => 0]);
    $check(true, $member->allowsWallet($customerId, 2), 'Synced zero points are membership');
    $check(false, $member->allowsWallet($customerId, $storeId), 'Other store cannot unlock wallet');
    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->setStore($store)->assignCustomer($customer)->setIsActive(true)->setCustomerIsGuest(false);
    $quote->addProduct($om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById(1, false, $storeId), 1);
    $address = ['firstname' => 'Enrollment', 'lastname' => 'Fixture', 'street' => ['Teststraat 1'], 'city' => 'Teststad',
        'country_id' => 'NL', 'postcode' => '1000AA', 'telephone' => '0612345678', 'email' => $email, 'save_in_address_book' => 0];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
    $quote->getPayment()->setMethod('checkmo');
    $quote->collectTotals();
    $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
    $choice->save((int) $quote->getId(), $storeId, true, 'required', 'Join fixture');
    $check([], $choice->get((int) $quote->getId(), 2), 'Quote choice is store scoped');
    $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
    $identity = ['store_id' => $storeId, 'identifier' => $email];
    $event = $outbox->find('loyaltyshop.opt_in_event', $identity);
    $check('pending', $event['status'] ?? null, 'Placed order creates opt-in atomically');
    $check(false, $member->allowsWallet($customerId, $storeId), 'Pending signup does not unlock wallet');
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
    $check(1, (int) $db->fetchOne($db->select()->from($outbox->table(), ['COUNT(*)'])->where('event_key = ?', $event['event_key'])), 'Repeated saves do not duplicate opt-in');
    $api = new LocalEnrollmentApi();
    $consumer = new \LoyaltyEngage\LoyaltyShop\Model\Queue\OptInConsumer($om->get(\LoyaltyEngage\LoyaltyShop\Helper\Data::class), $api);
    $consumer->deliver(json_decode($event['payload'], true));
    $check([['event' => 'LoyaltyEngageOptIn', 'identifier' => $email]], $api->calls[0][1], 'Exact wire schema, no audit fields');
    $db->update($outbox->table(), ['status' => 'sent'], ['entity_id = ?' => $event['entity_id']]);
    $check(true, $member->allowsWallet($customerId, $storeId), 'Confirmed opt-in unlocks before next metadata sync');
    $config->testMode = 'advisory';
    $check(true, $member->allowsWallet(0, $storeId), 'Informational mode does not gate wallet');
    echo json_encode(['enrollment_checks' => $checks, 'passed' => true, 'transport' => 'fake']) . "\n";
} finally { $db->rollBack(); echo "Enrollment fixtures rolled back.\n"; }
