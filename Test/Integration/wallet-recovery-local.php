<?php
declare(strict_types=1);

use LoyaltyEngage\LoyaltyShop\Cron\WalletHolds;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\DatabaseLocks;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') { throw new RuntimeException('Local opt-in required.'); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$scope = $om->get(ScopeConfigInterface::class);
if (parse_url((string) $scope->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to magento.test.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$ledger = $om->get(Ledger::class);
$selection = $om->get(Selection::class);
$locks = new DatabaseLocks($resource);
$logger = new \Psr\Log\NullLogger();
$cron = new WalletHolds($resource, $ledger, $selection, $locks, $logger);
$nonce = bin2hex(random_bytes(8));
$customerId = $orderId = 0;
$ids = [];
$checks = 0;
$check = static function ($expected, $actual) use (&$checks): void {
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException(json_encode(['expected' => $expected, 'actual' => $actual])); }
};
$due = static function () use ($db, $ledger): array {
    return $db->fetchCol($db->select()->from($ledger->table('quote'), ['quote_id'])->where('status = ?', 'reserved')
        ->where('updated_at < ?', gmdate('Y-m-d H:i:s', time() - 900)));
};
$email = 'wallet-recovery-' . $nonce . '@example.com';
$increment = 'WALLET-REVIEW-' . $nonce;
try {
    if ($due()) { throw new RuntimeException('Refusing to process unrelated due holds.'); }
    $store = $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore('default');
    $storeId = (int) $store->getId();
    $db->insert($resource->getTableName('customer_entity'), ['website_id' => (int) $store->getWebsiteId(), 'store_id' => $storeId,
        'email' => $email, 'firstname' => 'Wallet', 'lastname' => 'Recovery Fixture']);
    $customerId = (int) $db->lastInsertId();
    $db->insert($resource->getTableName('sales_order'), ['increment_id' => $increment, 'customer_id' => $customerId,
        'store_id' => $storeId, 'state' => 'new', 'status' => 'pending', 'base_currency_code' => 'EUR', 'order_currency_code' => 'EUR']);
    $orderId = (int) $db->lastInsertId();
    $db->insert($ledger->table('balance'), ['customer_id' => $customerId, 'store_id' => $storeId, 'balance_cents' => 10000, 'reserved_cents' => 4000]);
    foreach ([$increment, 'WALLET-MISSING-' . $nonce] as $reference) {
        $db->insert($resource->getTableName('quote'), ['customer_id' => $customerId, 'store_id' => $storeId, 'is_active' => 1]);
        $id = (int) $db->lastInsertId(); $ids[] = $id;
        $db->insert($ledger->table('quote'), ['quote_id' => $id, 'customer_id' => $customerId, 'store_id' => $storeId,
            'requested_cents' => 2000, 'held_cents' => 2000, 'status' => 'reserved', 'token' => bin2hex(random_bytes(32)),
            'increment_id' => $reference, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    }
    $cron->execute();
    $check('review', $selection->row($ids[0])['status']);
    $check('selected', $selection->row($ids[1])['status']);
    $check(8000, $ledger->available($customerId, $storeId));
    $check([], $due());
    $db->update($ledger->table('quote'), ['status' => 'reserved', 'held_cents' => 2000,
        'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600)], ['quote_id = ?' => $ids[1]]);
    $db->update($ledger->table('balance'), ['reserved_cents' => 4000], ['customer_id = ?' => $customerId, 'store_id = ?' => $storeId]);
    $failing = new class($resource, $ledger, new Config($scope, new Policy(new Money())), new Money()) extends Selection {
        public function release(int $quoteId): void { throw new RuntimeException('Simulated temporary recovery failure'); }
    };
    (new WalletHolds($resource, $ledger, $failing, $locks, $logger))->execute();
    $check('reserved', $selection->row($ids[1])['status']);
    $check([], $due());
    $check(6000, $ledger->available($customerId, $storeId));
} finally {
    if ($ids) {
        $db->delete($ledger->table('quote'), ['quote_id IN (?)' => $ids]);
        $db->delete($resource->getTableName('quote'), ['entity_id IN (?)' => $ids, 'customer_id = ?' => $customerId]);
    }
    if ($orderId) { $db->delete($resource->getTableName('sales_order'), ['entity_id = ?' => $orderId, 'increment_id = ?' => $increment]); }
    if ($customerId) { $db->delete($resource->getTableName('customer_entity'), ['entity_id = ?' => $customerId, 'email = ?' => $email]); }
}
$check(0, $ledger->balance($customerId, $storeId));
echo json_encode(['wallet_recovery_checks' => $checks, 'all_passed' => true, 'fixtures_removed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
