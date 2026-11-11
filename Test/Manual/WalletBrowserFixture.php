<?php
declare(strict_types=1);

// Local-only interactive checkout fixture. Retain test orders for audit; restore config.
if (getenv('LE_BROWSER_TEST') !== '1') { exit("Local opt-in required.\n"); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
if (parse_url($stores->getStore('default')->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to local Magento.');
}
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $resource->getConnection();
$file = BP . '/var/loyalty-wallet-browser-20261111.json';
$mode = $argv[1] ?? 'status';
$state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
$paths = ['loyalty/wallet/enabled' => '1', 'loyalty/wallet/spending_enabled' => '1',
    'loyalty/wallet/coins_required' => '0', 'loyalty/wallet/payment_methods' => 'free,checkmo,banktransfer',
    'payment/checkmo/active' => '1', 'payment/free/active' => '1',
    'loyalty/export/purchase_order_status' => 'wallet_browser_test_never',
    'sales_email/order/enabled' => '0', 'sales_email/invoice/enabled' => '0'];
$config = $resource->getTableName('core_config_data');
if ($mode === 'enable') {
    if ($state) { throw new RuntimeException('Fixture already exists; do not reseed.'); }
    $state = ['email' => 'wallet-browser-20261111@example.com', 'password' => 'LocalWallet!' . bin2hex(random_bytes(8)),
        'config' => [], 'restored' => false];
    foreach (['default', 'hyva'] as $code) {
        $id = (int) $stores->getStore($code)->getId();
        foreach ($paths as $path => $value) {
            $state['config'][] = ['store' => $id, 'path' => $path, 'test_value' => $value,
                'row' => $db->fetchRow($db->select()->from($config)->where('scope = ?', 'stores')
                    ->where('scope_id = ?', $id)->where('path = ?', $path)) ?: null];
        }
    }
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($file, 0600);
    $db->beginTransaction();
    try {
        $db->insert($resource->getTableName('customer_entity'), ['website_id' => 1, 'store_id' => 1,
            'email' => $state['email'], 'firstname' => 'Wallet', 'lastname' => 'Browser Test',
            'password_hash' => $om->get(\Magento\Framework\Encryption\EncryptorInterface::class)->getHash($state['password'], true)]);
        $state['customer_id'] = (int) $db->lastInsertId();
        foreach (['default', 'hyva'] as $code) {
            $id = (int) $stores->getStore($code)->getId();
            $db->insert($resource->getTableName('loyaltyshop_wallet_balance'), ['customer_id' => $state['customer_id'],
                'store_id' => $id, 'balance_cents' => 10000]);
        }
        foreach ($state['config'] as $entry) {
            $db->insertOnDuplicate($config, ['scope' => 'stores', 'scope_id' => $entry['store'],
                'path' => $entry['path'], 'value' => $entry['test_value']], ['value']);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    echo json_encode(['email' => $state['email'], 'password' => $state['password'], 'customer_id' => $state['customer_id']]) . "\n";
} elseif ($mode === 'resume') {
    if (!$state || !$state['restored'] || empty($state['customer_id'])) {
        throw new RuntimeException('Only a restored fixture can be resumed.');
    }
    $db->beginTransaction();
    try {
        foreach ($state['config'] as &$entry) {
            $entry['row'] = $db->fetchRow($db->select()->from($config)->where('scope = ?', 'stores')
                ->where('scope_id = ?', $entry['store'])->where('path = ?', $entry['path'])) ?: null;
            $db->insertOnDuplicate($config, ['scope' => 'stores', 'scope_id' => $entry['store'],
                'path' => $entry['path'], 'value' => $entry['test_value']], ['value']);
        }
        unset($entry);
        $state['restored'] = false;
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    echo "Test settings resumed; existing balances unchanged.\n";
} elseif ($mode === 'restore') {
    if (!$state || $state['restored']) { throw new RuntimeException('No active fixture.'); }
    $db->beginTransaction();
    try {
        foreach ($state['config'] as $entry) {
            $where = ['scope = ?' => 'stores', 'scope_id = ?' => $entry['store'], 'path = ?' => $entry['path']];
            $value = $db->fetchOne($db->select()->from($config, ['value'])->where('scope = ?', 'stores')
                ->where('scope_id = ?', $entry['store'])->where('path = ?', $entry['path']));
            if ($value !== $entry['test_value']) { throw new RuntimeException('Configuration changed during test; preserve user changes.'); }
            $db->delete($config, $where);
            if ($entry['row']) { $db->insert($config, $entry['row']); }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $state['restored'] = true;
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    echo "Store settings restored; fictional customer and test orders retained for audit.\n";
} elseif ($mode === 'status' && $state) {
    echo json_encode(['restored' => $state['restored'], 'customer_id' => $state['customer_id'],
        'orders' => $db->fetchAll($db->select()->from($resource->getTableName('sales_order'),
            ['entity_id', 'increment_id', 'store_id', 'state', 'status', 'grand_total', 'quote_id'])->where('customer_id = ?', $state['customer_id'])),
        'balances' => $db->fetchAll($db->select()->from($resource->getTableName('loyaltyshop_wallet_balance'))
            ->where('customer_id = ?', $state['customer_id']))], JSON_PRETTY_PRINT) . "\n";
} else { throw new RuntimeException('Use enable, resume, status or restore.'); }
