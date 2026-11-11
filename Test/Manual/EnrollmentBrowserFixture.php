<?php
declare(strict_types=1);

// Local browser fixture only; never sends enrollment events or spends real coins.
if (getenv('LE_BROWSER_TEST') !== '1') { exit("Local opt-in required.\n"); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
if (parse_url($stores->getStore('default')->getBaseUrl(), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Local demo only.');
}
$r = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $r->getConnection();
$config = $r->getTableName('core_config_data');
$data = $r->getTableName('loyaltyshop_customer_store_data');
$customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->get('wallet-browser-20261111@example.com', 1);
$id = (int) $customer->getId();
$file = BP . '/var/loyalty-enrollment-browser.json';
$state = is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
$mode = $argv[1] ?? 'status';
$paths = ['loyalty/enrollment/mode' => 'required', 'loyalty/enrollment/label' => 'Ja, ik wil sparen met Loyalty Engage'];
if ($mode === 'enable') {
    if ($state && !$state['restored']) { throw new RuntimeException('Fixture already active.'); }
    $state = ['restored' => false, 'config' => [], 'data' => []];
    foreach ([1, 2] as $storeId) {
        foreach ($paths as $path => $value) {
            $state['config'][] = ['store' => $storeId, 'path' => $path, 'row' => $db->fetchRow($db->select()->from($config)
                ->where('scope = ?', 'stores')->where('scope_id = ?', $storeId)->where('path = ?', $path)) ?: null];
        }
        $state['data'][$storeId] = $db->fetchRow($db->select()->from($data)->where('customer_id = ?', $id)->where('store_id = ?', $storeId)) ?: null;
    }
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    foreach ($state['config'] as $entry) {
        $db->insertOnDuplicate($config, ['scope' => 'stores', 'scope_id' => $entry['store'], 'path' => $entry['path'], 'value' => $paths[$entry['path']]], ['value']);
    }
} elseif (in_array($mode, ['member', 'empty'], true)) {
    if (!$state || $state['restored']) { throw new RuntimeException('Enable fixture first.'); }
    $storeId = (int) ($argv[2] ?? 0);
    if (!in_array($storeId, [1, 2], true)) { throw new RuntimeException('Test stores only.'); }
    $values = array_fill_keys(\LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider::ATTRIBUTE_CODES, null);
    if ($mode === 'member') { $values['le_points'] = 0; }
    $db->insertOnDuplicate($data, ['customer_id' => $id, 'store_id' => $storeId] + $values, array_keys($values));
} elseif (in_array($mode, ['advisory', 'required', 'disabled'], true)) {
    if (!$state || $state['restored']) { throw new RuntimeException('Enable fixture first.'); }
    $db->update($config, ['value' => $mode], ['scope = ?' => 'stores', 'scope_id IN (?)' => [1, 2], 'path = ?' => 'loyalty/enrollment/mode']);
} elseif ($mode === 'restore') {
    if (!$state || $state['restored']) { throw new RuntimeException('No active fixture.'); }
    foreach ($state['config'] as $entry) {
        $db->delete($config, ['scope = ?' => 'stores', 'scope_id = ?' => $entry['store'], 'path = ?' => $entry['path']]);
        if ($entry['row']) { $db->insert($config, $entry['row']); }
    }
    foreach ($state['data'] as $storeId => $row) {
        $db->delete($data, ['customer_id = ?' => $id, 'store_id = ?' => $storeId]);
        if ($row) { $db->insert($data, $row); }
    }
    $state['restored'] = true;
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
} elseif ($mode !== 'status') { throw new RuntimeException('Invalid mode.'); }
$om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
echo json_encode(['mode' => $mode, 'restored' => $state['restored'] ?? null, 'customer_id' => $id,
    'choices' => $db->fetchAll($db->select()->from(['e' => $r->getTableName('loyaltyshop_enrollment_quote')])
        ->join(['q' => $r->getTableName('quote')], 'q.entity_id=e.quote_id', ['customer_id', 'is_active'])->where('q.customer_id = ?', $id))], JSON_PRETTY_PRINT) . "\n";
