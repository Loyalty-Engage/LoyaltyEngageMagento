<?php
declare(strict_types=1);

// Local demo only. Run enable, execute HTTP form checks, then ALWAYS restore.
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') { throw new RuntimeException('Local opt-in required.'); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
if (parse_url((string) $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to magento.test.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$file = BP . '/var/loyalty_wallet_storefront_fixture.json';
$mode = $argv[1] ?? '';
$configTable = $resource->getTableName('core_config_data');
$balanceTable = $resource->getTableName('loyaltyshop_wallet_balance');
$paths = ['loyalty/wallet/enabled' => '1', 'loyalty/wallet/spending_enabled' => '1',
    'loyalty/wallet/coins_required' => ($argv[2] ?? '') === 'coins' ? '1' : '0',
    'loyalty/wallet/name' => 'Demo Wallet', 'loyalty/wallet/payment_methods' => 'free,checkmo,banktransfer'];
if ($mode === 'enable') {
    if (file_exists($file)) { throw new RuntimeException('Restore the existing fixture first.'); }
    $customerId = (int) $db->fetchOne($db->select()->from($resource->getTableName('customer_entity'), ['entity_id'])
        ->where('email = ?', 'personalization-demo@example.com')->limit(1));
    $stores = $db->fetchCol($db->select()->from($resource->getTableName('store'), ['store_id'])
        ->where('code IN (?)', ['default', 'hyva']));
    if (!$customerId || count($stores) !== 2) { throw new RuntimeException('Demo fixtures missing.'); }
    $snapshot = ['customer_id' => $customerId, 'stores' => $stores, 'config' => [], 'balances' => []];
    foreach ($stores as $storeId) {
        foreach ($paths as $path => $value) {
            $snapshot['config'][] = ['scope_id' => (int) $storeId, 'path' => $path,
                'row' => $db->fetchRow($db->select()->from($configTable)->where('scope = ?', 'stores')
                    ->where('scope_id = ?', (int) $storeId)->where('path = ?', $path)) ?: null];
        }
        $row = $db->fetchRow($db->select()->from($balanceTable)->where('customer_id = ?', $customerId)->where('store_id = ?', (int) $storeId));
        if ($row && ((int) $row['balance_cents'] !== 0 || (int) $row['reserved_cents'] !== 0
            || (int) $row['funded_cents'] !== 0 || (int) $row['reserved_funded'] !== 0)) {
            throw new RuntimeException('Refusing to overwrite a nonzero balance.');
        }
        $snapshot['balances'][(string) $storeId] = $row ?: null;
    }
    file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($file, 0600);
    $db->beginTransaction();
    try {
        foreach ($stores as $storeId) {
            foreach ($paths as $path => $value) {
                $db->insertOnDuplicate($configTable, ['scope' => 'stores', 'scope_id' => (int) $storeId,
                    'path' => $path, 'value' => $value], ['value']);
            }
            $db->insertOnDuplicate($balanceTable, ['customer_id' => $customerId, 'store_id' => (int) $storeId,
                'balance_cents' => 10000, 'reserved_cents' => 0], ['balance_cents', 'reserved_cents']);
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack(); throw $exception;
    }
    echo "Local fictional-customer wallet fixture enabled. No orders or payments may be placed with this fixture.\n";
} elseif ($mode === 'restore') {
    if (!file_exists($file)) { throw new RuntimeException('No fixture snapshot.'); }
    $snapshot = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $db->beginTransaction();
    try {
        foreach ($snapshot['config'] as $entry) {
            $where = ['scope = ?' => 'stores', 'scope_id = ?' => $entry['scope_id'], 'path = ?' => $entry['path']];
            $db->delete($configTable, $where);
            if ($entry['row']) { $db->insert($configTable, $entry['row']); }
        }
        foreach ($snapshot['stores'] as $storeId) {
            $row = $db->fetchRow($db->select()->from($balanceTable)->where('customer_id = ?', $snapshot['customer_id'])->where('store_id = ?', (int) $storeId));
            if (!$row || (int) $row['balance_cents'] !== 10000 || (int) $row['reserved_cents'] !== 0
                || (int) $row['funded_cents'] !== 0 || (int) $row['reserved_funded'] !== 0) {
                throw new RuntimeException('Fixture balance changed unexpectedly. Do not overwrite financial activity.');
            }
            $db->delete($balanceTable, ['customer_id = ?' => $snapshot['customer_id'], 'store_id = ?' => (int) $storeId]);
            if ($snapshot['balances'][(string) $storeId]) { $db->insert($balanceTable, $snapshot['balances'][(string) $storeId]); }
        }
        $db->commit();
        unlink($file);
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
    echo "Store-scoped wallet config and fictional balances restored.\n";
} else { throw new RuntimeException('Use enable or restore.'); }
$om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
