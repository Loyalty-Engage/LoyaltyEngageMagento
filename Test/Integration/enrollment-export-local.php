<?php
declare(strict_types=1);

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{ExportCsv, ExportRows};
use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\{Bootstrap, ResourceConnection, State};
use Magento\Store\Model\StoreManagerInterface;

if (getenv('LE_EXPORT_LOCAL_TEST') !== '1') {
    throw new RuntimeException('Local opt-in required.');
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(State::class)->setAreaCode('adminhtml');
$stores = $om->get(StoreManagerInterface::class);
$hyva = $stores->getStore('hyva');
$default = $stores->getStore('default');
if (parse_url($default->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Local demo only.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$table = $resource->getTableName('customer_entity');
$prefix = 'le-export-' . bin2hex(random_bytes(5));
$outbox = $om->get(EventOutbox::class);
$checks = 0;
$check = static function ($expected, $actual, string $message) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode([$expected, $actual]));
    }
};
$db->beginTransaction();
try {
    $base = ['website_id' => $default->getWebsiteId(), 'store_id' => $default->getId(), 'firstname' => 'CSV', 'lastname' => 'Fixture'];
    $fixtures = [];
    for ($i = 0; $i < 505; ++$i) {
        $fixtures[] = ['email' => $prefix . '-' . $i . '@example.invalid'] + $base;
    }
    $db->insertMultiple($table, $fixtures);
    $ids = $db->fetchPairs($db->select()->from($table, ['email', 'entity_id'])->where('email LIKE ?', $prefix . '-%'));
    $first = $fixtures[0]['email'];
    $last = $fixtures[504]['email'];
    $db->insert($table, ['email' => $prefix . '-out@example.invalid', 'website_id' => 0, 'store_id' => 0] + $base);
    $wallet = $resource->getTableName('loyaltyshop_wallet_balance');
    $db->insertMultiple($wallet, [
        ['customer_id' => $ids[$first], 'store_id' => $hyva->getId(), 'balance_cents' => 1234, 'reserved_cents' => 111],
        ['customer_id' => $ids[$first], 'store_id' => $default->getId(), 'balance_cents' => 9999, 'reserved_cents' => 0],
    ]);
    $db->insert($resource->getTableName('loyaltyshop_customer_store_data'), [
        'customer_id' => $ids[$last], 'store_id' => $hyva->getId(), 'le_points' => 0,
    ]);
    $attribute = $om->get(\Magento\Eav\Model\Config::class)->getAttribute('customer', 'le_points');
    $db->insert($attribute->getBackendTable(), [
        'entity_id' => $ids[$first], 'attribute_id' => $attribute->getId(), 'value' => 0,
    ]);
    $outbox->publish('loyaltyshop.opt_in_event', json_encode([
        'event' => 'LoyaltyEngageOptIn', 'identifier' => $first, 'store_id' => (int) $hyva->getId(),
        'source' => 'registration',
    ], JSON_THROW_ON_ERROR));

    $share = new class extends Share {
        public function __construct() {}
        public function isWebsiteScope() { return true; }
    };
    $reader = $om->create(ExportRows::class, ['sharing' => $share]);
    $collect = static function ($store) use ($reader, $prefix): array {
        $stream = tmpfile();
        try {
            (new ExportCsv($reader))->write($stream, $store);
            rewind($stream);
            fread($stream, 3);
            fgetcsv($stream, 0, ',', '"', '');
            $rows = [];
            while ($row = fgetcsv($stream, 0, ',', '"', '')) {
                if (str_starts_with($row[3], $prefix)) {
                    $rows[$row[3]] = array_slice($row, 4);
                }
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    };
    $hyvaRows = $collect($hyva);
    $defaultRows = $collect($default);
    $check(505, count($hyvaRows), 'All eligible customer rows across batch boundaries');
    $check(505, count($defaultRows), 'Other website excluded in website sharing mode');
    $check(['pending', 'registration', '11.23', '1.11'], $hyvaRows[$first], 'Scoped event and balance with reservation');
    $check(['enrolled', 'magento_legacy', '99.99', '0.00'], $defaultRows[$first], 'Legacy zero only in originating store and no cross-store event');
    $check(['enrolled', 'loyalty_sync', '0.00', '0.00'], $hyvaRows[$last], 'Scoped zero constitutes membership');
    $check(['not_enrolled', '', '0.00', '0.00'], $defaultRows[$last], 'No fallback to another store');
    $check(['not_enrolled', '', '0.00', '0.00'], $hyvaRows[$fixtures[1]['email']], 'No loyalty information');
    echo json_encode(['checks' => $checks, 'sample_customers' => 505, 'passed' => true]), PHP_EOL;
} finally {
    $db->rollBack();
}
