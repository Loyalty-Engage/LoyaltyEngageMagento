<?php
declare(strict_types=1);

use LoyaltyEngage\LoyaltyShop\Api\CustomerLoyaltyInterface;
use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Model\Enrollment\Membership;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\App\{Bootstrap, ResourceConnection, State};
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Store\Model\StoreManagerInterface;

if (getenv('LE_CONTACT_LOCAL_TEST') !== '1') {
    throw new RuntimeException('Local opt-in required.');
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('webapi_rest');
$stores = $om->get(StoreManagerInterface::class);
$default = $stores->getStore('default');
$hyva = $stores->getStore('hyva');
if (parse_url($default->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Local demo only.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$input = $om->get(ServiceInputProcessor::class);
$service = $om->get(CustomerLoyaltyInterface::class);
$provider = $om->get(CustomerLoyaltyDataProvider::class);
$checks = 0;
$check = static function ($expected, $actual, string $message) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode([$expected, $actual]));
    }
};
$db->beginTransaction();
try {
    $email = 'le-contact-' . bin2hex(random_bytes(6)) . '@example.invalid';
    $db->insert($resource->getTableName('customer_entity'), [
        'email' => $email, 'website_id' => $default->getWebsiteId(), 'store_id' => $default->getId(),
        'firstname' => 'Contact', 'lastname' => 'Fixture',
    ]);
    $id = (int) $db->lastInsertId();
    $defaultId = (int) $default->getId();
    $hyvaId = (int) $hyva->getId();
    $attribute = $om->get(Config::class)->getAttribute('customer', 'le_points');
    $db->insert($attribute->getBackendTable(), [
        'entity_id' => $id, 'attribute_id' => $attribute->getId(), 'value' => 17,
    ]);
    $customer = $om->get(CustomerRepositoryInterface::class)->getById($id);
    $update = static function (array $body) use ($input, $service, $email) {
        return $service->updateCustomerLoyaltyData(...$input->process(
            CustomerLoyaltyInterface::class,
            'updateCustomerLoyaltyData',
            ['email' => $email] + $body
        ));
    };

    $check(['le_contact_id'], $update(['store_code' => 'default', 'le_contact_id' => '00042'])->getUpdatedFields(),
        'Snake-case REST binding reports the contact field');
    $check('00042', $provider->getContactId($id, $defaultId), 'Contact ID keeps leading zeros');
    $check(null, $provider->getContactId($id, $hyvaId), 'Contact ID never falls back across stores');
    $check('17', (string) $provider->getCustomerLoyaltyData($customer, $defaultId)['le_points'],
        'Contact-only update preserves legacy points');
    $check(false, Membership::hasFields($provider->getCustomerLoyaltyData($customer, $hyvaId)),
        'Contact ID does not confer membership in another store');
    $check(['le_reserved_coins', 'le_contact_id'], $update([
        'store_code' => 'hyva', 'le_reserved_coins' => 12, 'le_contact_id' => 'tenant-b-42',
        'le_bought_discount_code' => 'ignored',
    ])->getUpdatedFields(), 'Bridge update saves known fields and ignores unknown discount field');
    $check('tenant-b-42', $provider->getContactId($id, $hyvaId), 'Second store has its own contact ID');
    $check('12', (string) $provider->getCustomerLoyaltyData($customer, $hyvaId)['le_reserved_coins'], 'Reserved coins stored');
    $check(['le_reserved_coins'], $update(['store_code' => 'hyva', 'le_reserved_coins' => 0])->getUpdatedFields(),
        'Old clients can still update reserved coins to zero without sending a contact ID');
    $check('0', (string) $provider->getCustomerLoyaltyData($customer, $hyvaId)['le_reserved_coins'], 'Zero is a valid balance update');
    $check([], $update(['store_code' => 'default', 'le_contact_id' => ' '])->getUpdatedFields(), 'Blank contact ID does not clear it');
    $check('00042', $provider->getContactId($id, $defaultId), 'Existing contact survives blank and second-store updates');
    $check(false, $update(['store_code' => 'hyva', 'le_contact_id' => str_repeat('x', 256), 'le_reserved_coins' => 99])->getSuccess(),
        'Invalid ID rejects update');
    $check('0', (string) $provider->getCustomerLoyaltyData($customer, $hyvaId)['le_reserved_coins'], 'Invalid ID does not partially update balance');
    $check('tenant-b-42', $provider->getContactId($id, $hyvaId), 'Old client and invalid ID do not erase stored contact');
    echo json_encode(['checks' => $checks, 'passed' => true, 'transport' => 'Magento REST parameter binding'], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    $db->rollBack();
}
