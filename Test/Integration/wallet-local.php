<?php
declare(strict_types=1);

// Local-demo transaction test. Never enables wallet config or calls a gateway.
// Run: LOYALTY_WALLET_LOCAL_TEST=1 php app/code/LoyaltyEngage/LoyaltyShop/Test/Integration/wallet-local.php
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Accrual;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use LoyaltyEngage\LoyaltyShop\Observer\Wallet\Capture;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') {
    throw new RuntimeException('Explicit local test opt-in required.');
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$scope = $om->get(ScopeConfigInterface::class);
$host = parse_url((string) $scope->getValue('web/secure/base_url'), PHP_URL_HOST);
if ($host !== 'magento.test') {
    throw new RuntimeException('This fixture is restricted to magento.test.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$orderResource = $om->get(\Magento\Sales\Model\ResourceModel\Order::class);
if ($orderResource->getConnection() !== $db) {
    throw new RuntimeException('A shared transactional sales connection is required.');
}
$customerId = (int) $db->fetchOne($db->select()->from($resource->getTableName('customer_entity'), ['entity_id'])
    ->where('email = ?', 'personalization-demo@example.com')->limit(1));
$stores = $db->fetchCol($db->select()->from($resource->getTableName('store'), ['store_id'])
    ->where('store_id > 0')->where('is_active = ?', 1)->order('store_id')->limit(2));
if (!$customerId || count($stores) !== 2) {
    throw new RuntimeException('The fictional demo customer and two stores must exist.');
}
$checkCount = 0;
$check = static function ($expected, $actual, string $message) use (&$checkCount): void {
    ++$checkCount;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode(['expected' => $expected, 'actual' => $actual]));
    }
};
$testScope = new class implements ScopeConfigInterface {
    public bool $includingTax = false;
    public function getValue($path, $scopeType = 'default', $scopeCode = null)
    {
        return str_ends_with($path, '/rates') ? json_encode([
            ['tier' => 'base', 'percentage' => '1'], ['tier' => 'gold', 'percentage' => '2'],
        ]) : null;
    }
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null)
    {
        return str_ends_with($path, '/including_tax') ? $this->includingTax : true;
    }
};
$money = new Money();
$policy = new Policy($money);
$capture = new Capture(new Config($testScope, $policy), $resource, $money, $policy);
$ledger = $om->get(Ledger::class);
$accrual = $om->get(Accrual::class);
$balances = array_map(fn($store): int => $ledger->balance($customerId, (int) $store), $stores);
$before = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $ledger->table('ledger'));
$db->beginTransaction();
try {
    $makeOrder = static function (int $storeId, bool $captured = true, string $tier = 'base') use ($om, $db, $resource, $customerId, $capture, $orderResource): Order {
        $db->insertOnDuplicate($resource->getTableName('loyaltyshop_customer_store_data'), [
            'customer_id' => $customerId, 'store_id' => $storeId, 'le_current_tier' => $tier,
        ], ['le_current_tier']);
        $order = $om->create(Order::class);
        $order->addData([
            'store_id' => $storeId, 'customer_id' => $customerId, 'customer_is_guest' => 0,
            'customer_email' => 'personalization-demo@example.com', 'state' => 'new', 'status' => 'pending',
            'base_currency_code' => 'EUR', 'order_currency_code' => 'EUR', 'store_currency_code' => 'EUR',
            'global_currency_code' => 'EUR', 'increment_id' => 'WALLET-TEST-' . bin2hex(random_bytes(6)),
            'base_grand_total' => '208.90', 'grand_total' => '208.90',
            'base_subtotal' => '100.00', 'subtotal' => '100.00',
        ]);
        $item = $om->create(\Magento\Sales\Model\Order\Item::class);
        $item->addData([
            'sku' => 'wallet-isolated-fixture', 'name' => 'Wallet isolated fixture', 'product_type' => 'simple',
            'qty_ordered' => 1, 'price' => '100.00', 'base_price' => '100.00', 'row_total' => '100.00',
            'base_row_total' => '100.00', 'discount_amount' => '10.00', 'base_discount_amount' => '10.00',
            'tax_amount' => '18.90', 'base_tax_amount' => '18.90',
        ]);
        $order->setItems([$item]);
        $payment = $om->create(\Magento\Sales\Model\Order\Payment::class);
        $payment->setMethod('checkmo');
        $order->setPayment($payment);
        if ($captured) {
            $capture->execute(new Observer(['event' => new \Magento\Framework\Event(['order' => $order])]));
        }
        $orderResource->save($order);
        return $order;
    };
    $complete = static function (Order $order) use ($orderResource): void {
        $order->setState('complete')->setStatus('complete');
        $orderResource->save($order);
    };
    $refund = static function (Order $order, string $amount, string $tax = '0') use ($om): Creditmemo {
        $memo = $om->create(Creditmemo::class);
        $memo->setOrder($order)->setOrderId($order->getId())->setStoreId($order->getStoreId())
            ->setState(Creditmemo::STATE_REFUNDED);
        $item = $om->create(\Magento\Sales\Model\Order\Creditmemo\Item::class);
        $item->setOrderItemId($order->getAllItems()[0]->getId())->setBaseRowTotal($amount)->setBaseTaxAmount($tax)->setQty(1);
        $memo->setItems([$item]);
        $om->get(\Magento\Sales\Model\ResourceModel\Order\Creditmemo::class)->save($memo);
        return $memo;
    };
    $first = $makeOrder((int) $stores[0]);
    $check($balances[0], $ledger->balance($customerId, (int) $stores[0]), 'Pending orders cannot spend accrued rewards');
    $check(9000, $first->getData(Capture::SNAPSHOT)['eligible_cents'], 'Discounted merchandise excludes tax and shipping');
    $complete($first);
    $check($balances[0] + 90, $ledger->balance($customerId, (int) $stores[0]), '1% credit');
    $orderResource->save($first);
    $check($balances[0] + 90, $ledger->balance($customerId, (int) $stores[0]), 'Order resave idempotency');
    $memo = $refund($first, '30.00');
    $check($balances[0] + 60, $ledger->balance($customerId, (int) $stores[0]), 'Partial clawback');
    $accrual->refunded($memo);
    $check($balances[0] + 60, $ledger->balance($customerId, (int) $stores[0]), 'Refund resave idempotency');
    $refund($first, '60.00');
    $check($balances[0], $ledger->balance($customerId, (int) $stores[0]), 'Full clawback exact');
    $second = $makeOrder((int) $stores[1]);
    $db->update($resource->getTableName('loyaltyshop_customer_store_data'), ['le_current_tier' => 'gold'],
        ['customer_id = ?' => $customerId, 'store_id = ?' => (int) $stores[1]]);
    $complete($second);
    $check($balances[1] + 90, $ledger->balance($customerId, (int) $stores[1]), 'Historical tier snapshot');
    $check($balances[0], $ledger->balance($customerId, (int) $stores[0]), 'Store isolation');
    $second->setState('canceled')->setStatus('canceled');
    $orderResource->save($second);
    $orderResource->save($second);
    $check($balances[1], $ledger->balance($customerId, (int) $stores[1]), 'Cancellation correction once');
    $historical = $makeOrder((int) $stores[0], false);
    $complete($historical);
    $check($balances[0], $ledger->balance($customerId, (int) $stores[0]), 'No retrospective rewards');
    $early = $makeOrder((int) $stores[0]);
    $refund($early, '45.00');
    $check($balances[0], $ledger->balance($customerId, (int) $stores[0]), 'Pre-vesting refunds do not debit');
    $complete($early);
    $check($balances[0] + 45, $ledger->balance($customerId, (int) $stores[0]), 'Pre-vesting refund reduces future credit');
    $partialCancel = $makeOrder((int) $stores[1]);
    $partialCancel->getAllItems()[0]->setQtyCanceled('0.5000');
    $complete($partialCancel);
    $check($balances[1] + 45, $ledger->balance($customerId, (int) $stores[1]), 'Partially canceled quantities do not earn');
    $partialCancel->getAllItems()[0]->setQtyCanceled('1.0000');
    $orderResource->save($partialCancel);
    $orderResource->save($partialCancel);
    $check($balances[1], $ledger->balance($customerId, (int) $stores[1]), 'Additional cancellation clawback once');
    $gold = $makeOrder((int) $stores[1], true, 'gold');
    $complete($gold);
    $check($balances[1] + 180, $ledger->balance($customerId, (int) $stores[1]), 'Gold earns 2%');
    $testScope->includingTax = true;
    $inclusive = $makeOrder((int) $stores[1], true, 'gold');
    $check(10890, $inclusive->getData(Capture::SNAPSHOT)['eligible_cents'], 'Inclusive basis includes merchandise tax only');
    $complete($inclusive);
    $check($balances[1] + 398, $ledger->balance($customerId, (int) $stores[1]), '2% inclusive rounds once at order level');
    $testScope->includingTax = false;
    $refund($inclusive, '90.00', '18.90');
    $check($balances[1] + 180, $ledger->balance($customerId, (int) $stores[1]), 'Refund uses original tax policy');
} finally {
    $db->rollBack();
}
$check($before, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $ledger->table('ledger')), 'Fixture ledger rollback');
foreach ($stores as $i => $storeId) {
    $check($balances[$i], $ledger->balance($customerId, (int) $storeId), 'Fixture balance rollback');
}
echo json_encode(['wallet_accrual_checks' => $checkCount, 'all_passed' => true, 'fixtures_rolled_back' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
