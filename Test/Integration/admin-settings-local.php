<?php
declare(strict_types=1);

// Local configuration round trips only; all database writes are rolled back.
if (getenv('LE_ADMIN_LOCAL_TEST') !== '1') { exit("Local opt-in required.\n"); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
if (parse_url((string) $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
    ->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Local demo only.');
}
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$db = $resource->getConnection();
$table = $resource->getTableName('core_config_data');
$xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/system.xml');
$values = [
    'general/loyalty_api_url' => 'https://fixture.invalid',
    'general/tenant_id' => 'local-client', 'general/bearer_token' => 'local-secret-not-real',
    'general/minimum_order_value' => '50', 'general/minimum_order_value_message' => 'Minimum {{minimum}} / {{current}}',
    'general/minimum_order_value_bar_color' => '#123456', 'general/minimum_order_value_text_color' => '#ffffff',
    'general/loyalty_product_removed_message' => 'Removed below {{minimum}}',
    'export/purchase_order_status' => ['processing', 'complete'],
    'export/allowed_order_sources' => ['storefront', 'api'], 'export/excluded_order_comment_markers' => 'admin fixture',
    'shipping/free_shipping_tiers' => 'base,gold', 'shipping/tier_cache_duration' => '300',
    'enrollment/mode' => 'required', 'enrollment/label' => 'Join local fixture',
    'referral/url_template' => 'https://invite.example.invalid/join?ref={contact_id}',
    'wallet/name' => 'Fixture wallet', 'wallet/payment_methods' => ['checkmo', 'free'],
    'wallet/rates' => [['tier' => 'base', 'percentage' => '1'], ['tier' => 'gold', 'percentage' => '2']],
    'wallet/coins_required' => '0', 'wallet/redemption_sku_1' => 'WALLET_1', 'wallet/redemption_sku_5' => 'WALLET_5',
    'wallet/redemption_sku_10' => 'WALLET_10', 'wallet/redemption_sku_50' => 'WALLET_50',
    'personalizations/tenant_id' => '4e0e4abb-50e1-4953-b528-8dc6d571272e',
    'personalizations/currency' => 'EUR', 'personalizations/rules' => [
        ['key' => 'fixture', 'public_id' => 'test-1', 'placement' => 'manual', 'page_scope' => 'pages',
            'pages' => '', 'enabled' => 1, 'personalized' => 0, 'use_cart_value' => 0],
    ],
];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
};
$db->beginTransaction();
try {
    $fieldCounts = [];
    foreach ([['default', 0, '', '', 'showInDefault'], ['websites', 1, 'base', '', 'showInWebsite'],
        ['stores', 2, '', 'hyva', 'showInStore']] as [$scope, $id, $website, $store, $visibility]) {
        $groups = [];
        $expected = [];
        foreach ($xml->system->section->group as $group) {
            foreach ($group->field as $field) {
                if ((string) $field[$visibility] !== '1') { continue; }
                $path = (string) $group['id'] . '/' . (string) $field['id'];
                $value = $values[$path] ?? '1';
                if (isset($field->source_model)) {
                    $options = $om->get((string) $field->source_model)->toOptionArray();
                    $check(is_array($options) && count($options) > 0, 'Empty options: ' . $path);
                    if (!array_key_exists($path, $values)) { $value = (string) $options[0]['value']; }
                }
                $groups[(string) $group['id']]['fields'][(string) $field['id']] = ['value' => $value];
                $expected[] = 'loyalty/' . $path;
            }
        }
        $om->create(\Magento\Config\Model\Config::class)->setSection('loyalty')->setWebsite($website)
            ->setStore($store)->setGroups($groups)->save();
        $rows = $db->fetchPairs($db->select()->from($table, ['path', 'value'])->where('scope = ?', $scope)
            ->where('scope_id = ?', $id)->where('path IN (?)', $expected));
        foreach ($expected as $path) { $check(array_key_exists($path, $rows), 'Missing saved field: ' . $scope . '/' . $path); }
        $check($rows['loyalty/enrollment/mode'] === 'required', 'Enrollment mode round trip');
        $check($rows['loyalty/enrollment/label'] === 'Join local fixture', 'Checkbox label round trip');
        $check($rows['loyalty/referral/url_template'] === $values['referral/url_template'], 'Invitation URL template round trip');
        $check($rows['loyalty/export/purchase_order_status'] === 'processing,complete', 'Multiselect round trip');
        $check(count(json_decode($rows['loyalty/wallet/rates'], true)) === 2, 'Wallet rate rows round trip');
        $check(count(json_decode($rows['loyalty/personalizations/rules'], true)) === 1, 'Personalization rows round trip');
        $check($om->get(\Magento\Framework\Encryption\EncryptorInterface::class)
            ->decrypt($rows['loyalty/general/bearer_token']) === 'local-secret-not-real', 'Encrypted secret round trip');
        $fieldCounts[$scope] = count($expected);
    }
    foreach ([
        [\LoyaltyEngage\LoyaltyShop\Model\Config\Backend\WalletRates::class, [['tier' => 'base', 'percentage' => '-1']]],
        [\LoyaltyEngage\LoyaltyShop\Model\Config\Backend\WalletRedemptionSku::class, 'INVALID SKU!'],
        [\LoyaltyEngage\LoyaltyShop\Model\Config\Backend\PersonalizationCurrency::class, 'EURO'],
        [\LoyaltyEngage\LoyaltyShop\Model\Config\Backend\PersonalizationTenant::class, '<script src="https://evil.invalid"></script>'],
        [\LoyaltyEngage\LoyaltyShop\Model\Config\Backend\InvitationUrl::class, 'https://{contact_id}.example.invalid/'],
    ] as [$class, $value]) {
        $rejected = false;
        try { $om->create($class)->setValue($value)->beforeSave(); }
        catch (\Magento\Framework\Exception\LocalizedException | \InvalidArgumentException $e) { $rejected = true; }
        $check($rejected, 'Invalid admin value accepted: ' . $class);
    }
    echo json_encode(['admin_config_checks' => $checks, 'fields_saved_per_scope' => $fieldCounts, 'rollback' => true]), PHP_EOL;
} finally {
    $db->rollBack();
    $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
}
