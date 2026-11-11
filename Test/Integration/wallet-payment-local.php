<?php
declare(strict_types=1);

// Explicit local-demo opt-in; all financial fixture writes are rolled back.
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') {
    throw new RuntimeException('Local wallet test opt-in required.');
}
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
if (parse_url((string) $om->get(ScopeConfigInterface::class)->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to magento.test.');
}
$scope = $om->get(\Magento\Framework\App\MutableScopeConfig::class);
$storeCode = getenv('LOYALTY_WALLET_TEST_STORE') ?: 'default';
if (!in_array($storeCode, ['default', 'hyva'], true)) {
    throw new RuntimeException('Only the two local demo stores are allowed.');
}
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore($storeCode);
$storeManager->setCurrentStore($store);
$storeId = (int) $store->getId();
foreach ([
    'loyalty/general/module_enable' => 1, 'loyalty/wallet/enabled' => 1,
    'loyalty/wallet/spending_enabled' => 1, 'loyalty/wallet/payment_methods' => 'free,checkmo,banktransfer',
    'loyalty/wallet/rates' => json_encode([['tier' => 'base', 'percentage' => '1']]),
    'loyalty/export/purchase_order_status' => 'wallet_test_never',
    'payment/checkmo/active' => 1, 'payment/free/active' => 1,
    'carriers/flatrate/active' => 1, 'carriers/flatrate/price' => '5', 'carriers/flatrate/type' => 'O',
] as $path => $value) {
    $scope->setValue($path, $value);
    foreach ([$storeId, $storeCode, null] as $code) { $scope->setValue($path, $value, 'store', $code); }
}
// Isolate the fixture from the compiled object manager's already shared config.
// This changes only this CLI process; no core_config_data or env.php writes.
$walletConfig = $om->get(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Config::class);
(new ReflectionProperty(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Config::class, 'scopeConfig'))->setValue($walletConfig, $scope);
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->get('personalization-demo@example.com', (int) $store->getWebsiteId());
$customerId = (int) $customer->getId();
$ledger = $om->get(Ledger::class);
$selection = $om->get(Selection::class);
$tender = $om->get(Tender::class);
$documents = $om->get(Documents::class);
$guard = $om->get(Guard::class);
$before = $ledger->balance($customerId, $storeId);
$beforeAvailable = $ledger->available($customerId, $storeId);
$count = 0;
$check = static function ($expected, $actual, string $message) use (&$count): void {
    ++$count;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode(['expected' => $expected, 'actual' => $actual]));
    }
};
$db->beginTransaction();
try {
    $db->insertOnDuplicate($ledger->table('balance'), ['customer_id' => $customerId, 'store_id' => $storeId,
        'balance_cents' => 10000, 'reserved_cents' => 0], ['balance_cents', 'reserved_cents']);
    $db->insertOnDuplicate($resource->getTableName('loyaltyshop_customer_store_data'), [
        'customer_id' => $customerId, 'store_id' => $storeId, 'le_current_tier' => 'base',
    ], ['le_current_tier']);
    $makeQuote = static function ($customerOverride = null) use ($om, $store, $customer) {
        $account = $customerOverride ?? $customer;
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store)->assignCustomer($account)->setIsActive(true)->setIsMultiShipping(false);
        $quote->setCustomerIsGuest(false)->setCheckoutMethod('customer');
        $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById(1, false, (int) $store->getId());
        $quote->addProduct($product, 2);
        $address = ['firstname' => 'Wallet', 'lastname' => 'Fixture', 'street' => ['Teststraat 1'],
            'city' => 'Teststad', 'postcode' => '1000AA', 'country_id' => 'NL', 'telephone' => '0612345678',
            'email' => $account->getEmail(), 'save_in_address_book' => 0];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setSameAsBilling(true)
            ->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
        $quote->getPayment()->setMethod('checkmo');
        $quote->collectTotals();
        $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
        return $quote;
    };
    $place = static function ($quote, int $amount, bool $historicalRefund = true) use ($om, $selection, $db, $ledger) {
        $selection->choose($quote, $amount);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $quote->getPayment()->setMethod((float) $quote->getBaseGrandTotal() < .0001 ? 'free' : 'checkmo');
        $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
        if ($historicalRefund) {
            // Keep testing the restoration promise of historical tenders.
            $db->update($ledger->table('tender'), ['restore_on_refund' => 1], ['order_id = ?' => (int) $order->getId()]);
        }
        return $order;
    };
    $invoice = static function ($order, ?array $qty = null) use ($om) {
        $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)->prepareInvoice($order, $qty ?? []);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
        $om->create(\Magento\Framework\DB\Transaction::class)->addObject($invoice)->addObject($order)->save();
        return $invoice;
    };
    $refund = static function ($order, array $qty, $invoice = null, $shipping = 0) use ($om) {
        $factory = $om->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $data = ['qtys' => $qty, 'shipping_amount' => $shipping];
        $memo = $invoice ? $factory->createByInvoice($invoice, $data) : $factory->createByOrder($order, $data);
        return $om->get(\Magento\Sales\Model\Service\CreditmemoService::class)->refund($memo, true);
    };
    $shippingQuote = $makeQuote();
    $shippingQuote->getShippingAddress()->setShippingMethod(null)->setCollectShippingRates(true);
    $selection->choose($shippingQuote, 10000);
    $shippingQuote->setTotalsCollectedFlag(false)->collectTotals();
    $om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($shippingQuote);
    $shippingInfo = $om->create(\Magento\Checkout\Api\Data\ShippingInformationInterface::class);
    $shippingInfo->setShippingAddress($shippingQuote->getShippingAddress())
        ->setShippingCarrierCode('flatrate')->setShippingMethodCode('flatrate');
    $paymentDetails = $om->get(\Magento\Checkout\Api\ShippingInformationManagementInterface::class)
        ->saveAddressInformation((int) $shippingQuote->getId(), $shippingInfo);
    $segments = $paymentDetails->getTotals()->getTotalSegments();
    $walletSegment = array_values(array_filter($segments, static fn($segment) => $segment->getCode() === 'loyalty_wallet'));
    $expectedTender = -(float) $paymentDetails->getTotals()->getSubtotal() - (float) $paymentDetails->getTotals()->getShippingAmount();
    $check($expectedTender, (float) $walletSegment[0]->getValue(), 'Shipping information response includes updated wallet tender');
    $check(0.0, (float) $paymentDetails->getTotals()->getGrandTotal(), 'Shipping information response keeps full wallet total zero');
    $forfeitOrder = $place($makeQuote(), 2000, false);
    $check(0, (int) $tender->get($forfeitOrder)['restore_on_refund'], 'New orders snapshot forfeiture policy');
    $forfeitInvoice = $invoice($forfeitOrder);
    $forfeitItem = (int) $forfeitOrder->getAllVisibleItems()[0]->getId();
    $forfeitMemo = $refund($forfeitOrder, [$forfeitItem => 2], $forfeitInvoice, $forfeitOrder->getBaseShippingAmount());
    $check(8000, $ledger->balance($customerId, $storeId), 'Return does not restore spent wallet credit');
    $check((float) $forfeitOrder->getBaseGrandTotal(), (float) $forfeitMemo->getBaseGrandTotal(), 'Only original cash tender is refunded');
    $om->get(\Magento\Sales\Api\CreditmemoRepositoryInterface::class)->save($forfeitMemo);
    $check(8000, $ledger->balance($customerId, $storeId), 'Repeated return does not restore forfeited credit');
    // Reset this rollback-only fixture before running historical-policy cases.
    $db->update($ledger->table('balance'), ['balance_cents' => 10000],
        ['customer_id = ?' => $customerId, 'store_id = ?' => $storeId]);
    $quote = $makeQuote();
    $check(true, $om->get(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Config::class)->spending($storeId), 'Process-local wallet config must be enabled');
    if (!$selection->eligible($quote)) {
        throw new RuntimeException('Fixture eligibility: ' . json_encode([
            'active' => $quote->getIsActive(), 'customer' => (bool) $quote->getCustomerId(), 'guest' => $quote->getCustomerIsGuest(),
            'multi' => $quote->getIsMultiShipping(), 'edit' => $quote->getOrigOrderId(),
            'base' => $quote->getBaseCurrencyCode(), 'currency' => $quote->getQuoteCurrencyCode(),
        ]));
    }
    $gross = (int) round((float) $quote->getBaseGrandTotal() * 100);
    $check(true, $gross > 2000, 'Fixture gross exceeds partial wallet payment');
    $order = $place($quote, 2000);
    $check(8000, $ledger->balance($customerId, $storeId), 'Wallet debit at native order placement');
    $check(8000, $ledger->available($customerId, $storeId), 'Placement hold converted to payment');
    $check('committed', $selection->row((int) $quote->getId())['status'], 'Durable quote-order binding');
    $check($gross - 2000, (int) round((float) $order->getBaseGrandTotal() * 100), 'Cash remainder only');
    $check(2000, (int) $tender->get($order)['spent_cents'], 'Tender state');
    try {
        $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);
        throw new RuntimeException('A duplicate placement was accepted.');
    } catch (\Magento\Framework\Exception\LocalizedException) {
        $check(8000, $ledger->balance($customerId, $storeId), 'Lost-response retry cannot spend twice');
    }
    $inv = $invoice($order);
    $check($gross - 2000, (int) round((float) $inv->getBaseGrandTotal() * 100), 'Invoice cash remainder');
    $check(2000, (int) $documents->saved('invoice', (int) $inv->getId())['wallet_cents'], 'Invoice wallet allocation');
    $order->setState('complete')->setStatus('complete');
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
    $earned = (int) $order->getData(\LoyaltyEngage\LoyaltyShop\Observer\Wallet\Capture::SNAPSHOT)['earned_cents'];
    $check(33, $earned, 'No accrual on the proportionally wallet-paid merchandise portion');
    $check(8000 + $earned, $ledger->balance($customerId, $storeId), 'Native order completion accrual');
    $itemId = (int) $order->getAllVisibleItems()[0]->getId();
    $memo = $refund($order, [$itemId => 1], $inv);
    $allocated = $documents->saved('creditmemo', (int) $memo->getId());
    $check(true, (int) $allocated['wallet_cents'] > 0, 'Partial refund returns wallet tender');
    $check(8000 + $earned - 17 + (int) $allocated['wallet_cents'], $ledger->balance($customerId, $storeId), 'Wallet tender refund plus earned-credit clawback');
    $om->get(\Magento\Sales\Model\ResourceModel\Order\Creditmemo::class)->save($memo);
    $check(8000 + $earned - 17 + (int) $allocated['wallet_cents'], $ledger->balance($customerId, $storeId), 'Refund save idempotency');
    $memo2 = $refund($order, [$itemId => 1], $inv, $order->getBaseShippingAmount());
    $check(10000, $ledger->balance($customerId, $storeId), 'Full refund restores tender and reverses all earned credit');
    $fullQuote = $makeQuote();
    $fullGross = (int) round((float) $fullQuote->getBaseGrandTotal() * 100);
    $beforeFull = $ledger->balance($customerId, $storeId);
    $full = $place($fullQuote, $fullGross);
    $check(0, (int) round((float) $full->getBaseGrandTotal() * 100), 'Full-wallet zero cash order');
    $check($beforeFull - $fullGross, $ledger->balance($customerId, $storeId), 'Full-wallet debit once');
    $fullInvoice = $invoice($full);
    $check(0, (int) round((float) $fullInvoice->getBaseGrandTotal() * 100), 'Full-wallet zero cash invoice');
    $fullItemId = (int) $full->getAllVisibleItems()[0]->getId();
    $fullMemo = $refund($full, [$fullItemId => 2], $fullInvoice, $full->getBaseShippingAmount());
    $check(0, (int) round((float) $fullMemo->getBaseGrandTotal() * 100), 'Full-wallet zero cash refund');
    $check(true, (int) $documents->saved('creditmemo', (int) $fullMemo->getId())['wallet_cents'] > 0, 'Full-wallet return persisted');
    $check($beforeFull, $ledger->balance($customerId, $storeId), 'Full-wallet refund restores exact original balance');
    $cancelQuote = $makeQuote();
    $beforeCancel = $ledger->balance($customerId, $storeId);
    $cancel = $place($cancelQuote, 1000);
    $cancel->cancel();
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($cancel);
    $check($beforeCancel, $ledger->balance($customerId, $storeId), 'Uninvoiced cancellation restores wallet');
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($cancel);
    $check($beforeCancel, $ledger->balance($customerId, $storeId), 'Cancellation idempotency');
    $split = $place($makeQuote(), 2000);
    $splitItemId = (int) $split->getAllVisibleItems()[0]->getId();
    $splitFirst = $invoice($split, [$splitItemId => 1]);
    $splitSecond = $invoice($split, [$splitItemId => 1]);
    $check(2000, (int) $tender->get($split)['invoiced_wallet'], 'Two partial invoices conserve total wallet tender');
    $check($gross - 2000, (int) round(((float) $splitFirst->getBaseGrandTotal() + (float) $splitSecond->getBaseGrandTotal()) * 100), 'Two partial invoices conserve total cash tender');
    $om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute((int) $split->getId());
    $check(10000, $ledger->balance($customerId, $storeId), 'Split-invoice order refund restores exact wallet amount');
    $apiQuote = $makeQuote();
    $apiGross = (int) round((float) $apiQuote->getBaseGrandTotal() * 100);
    $apiOrder = $place($apiQuote, $apiGross);
    $apiInvoiceId = $om->get(\Magento\Sales\Api\InvoiceOrderInterface::class)->execute((int) $apiOrder->getId(), false);
    $check($apiGross, (int) $documents->saved('invoice', (int) $apiInvoiceId)['wallet_cents'], 'Native invoice API persists wallet allocation');
    $apiMemoId = $om->get(\Magento\Sales\Api\RefundInvoiceInterface::class)->execute((int) $apiInvoiceId, [], true);
    $check($apiGross, (int) $documents->saved('creditmemo', (int) $apiMemoId)['wallet_cents'], 'Native invoice-refund API supports zero-cash wallet orders');
    $check(10000, $ledger->balance($customerId, $storeId), 'Invoice-refund API returns full wallet tender');
    $apiMixed = $place($makeQuote(), 2000);
    $mixedInvoiceId = $om->get(\Magento\Sales\Api\InvoiceOrderInterface::class)->execute((int) $apiMixed->getId(), false);
    $mixedMemoId = $om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute((int) $apiMixed->getId());
    $check(2000, (int) $documents->saved('creditmemo', (int) $mixedMemoId)['wallet_cents'], 'Native offline order-refund API returns only wallet tender');
    $check(10000, $ledger->balance($customerId, $storeId), 'Native mixed-tender API round trip');
    $stale = $place($makeQuote(), 2000);
    $invoiceService = $om->get(\Magento\Sales\Model\Service\InvoiceService::class);
    $firstDraft = $invoiceService->prepareInvoice($stale);
    $secondDraft = $invoiceService->prepareInvoice($stale);
    $firstDraft->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
    $om->create(\Magento\Framework\DB\Transaction::class)->addObject($firstDraft)->addObject($stale)->save();
    try {
        $secondDraft->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
        throw new RuntimeException('A stale duplicate invoice was registered.');
    } catch (\Magento\Framework\Exception\LocalizedException) {
        $check(2000, (int) $tender->get($stale)['invoiced_wallet'], 'Stale invoice rejected before another capture');
    }
    $om->get(\Magento\Sales\Api\RefundOrderInterface::class)->execute((int) $stale->getId());
    $check(10000, $ledger->balance($customerId, $storeId), 'Stale-invoice scenario settles exactly');
    $pdfTotal = $om->create(\LoyaltyEngage\LoyaltyShop\Model\Wallet\PdfTotal::class);
    $pdfTotal->setSource($inv)->setOrder($order);
    $check(-20, $pdfTotal->getAmount(), 'Invoice PDF deduction matches original wallet tender');
    $pdf = $om->get(\Magento\Sales\Model\Order\Pdf\Invoice::class)->getPdf([$inv])->render();
    $check(true, str_starts_with($pdf, '%PDF'), 'Native wallet invoice PDF renders');
    $scope->setValue('loyalty/wallet/enabled', 0, 'store', $storeId);
    $ordinary = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($makeQuote());
    $ordinaryInvoice = $invoice($ordinary);
    $check([], $tender->get($ordinary), 'Disabled-wallet native order has no tender state');
    $refund($ordinary, [(int) $ordinary->getAllVisibleItems()[0]->getId() => 2], $ordinaryInvoice, $ordinary->getBaseShippingAmount());
    $check(10000, $ledger->balance($customerId, $storeId), 'Disabled wallet leaves ordinary invoice/refund flows unchanged');
    $scope->setValue('loyalty/wallet/enabled', 1, 'store', $storeId);
    $orphanCustomer = $om->create(\Magento\Customer\Api\Data\CustomerInterface::class);
    $orphanCustomer->setWebsiteId((int) $store->getWebsiteId())->setStoreId($storeId)
        ->setEmail('wallet-orphan-' . bin2hex(random_bytes(6)) . '@example.com')->setFirstname('Wallet')->setLastname('Deleted Fixture');
    $orphanCustomer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->save($orphanCustomer);
    $orphanId = (int) $orphanCustomer->getId();
    $db->insert($ledger->table('balance'), ['customer_id' => $orphanId, 'store_id' => $storeId, 'balance_cents' => 10000, 'reserved_cents' => 0]);
    $orphanOrder = $place($makeQuote($orphanCustomer), 2000);
    $orphanInvoice = $invoice($orphanOrder);
    // Remove only this transaction's newly created fixture, not a real profile.
    // Native FK cascades simulate a deleted owner without changing admin security.
    $db->delete($resource->getTableName('customer_entity'), ['entity_id = ?' => $orphanId, 'email = ?' => $orphanCustomer->getEmail()]);
    $orphanMemo = $refund($orphanOrder, [(int) $orphanOrder->getAllVisibleItems()[0]->getId() => 2], $orphanInvoice, $orphanOrder->getBaseShippingAmount());
    $unclaimed = $db->fetchOne($db->select()->from($ledger->table('ledger'), ['amount_cents'])
        ->where('operation_key = ?', 'wallet:refund-payment:' . $orphanMemo->getId())->where('kind = ?', 'unclaimed_payment_refund'));
    $check(2000, (int) $unclaimed, 'Deleted-account refund remains auditable for merchant reconciliation');
    $check(0, $ledger->balance($orphanId, $storeId), 'Deleted account balance is never silently recreated');
    $partialCancel = $place($makeQuote(), 2000);
    $partialInvoice = $invoice($partialCancel, [(int) $partialCancel->getAllVisibleItems()[0]->getId() => 1]);
    $invoiceWallet = (int) $documents->saved('invoice', (int) $partialInvoice->getId())['wallet_cents'];
    $partialCancel->cancel();
    $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($partialCancel);
    $check(10000 - $invoiceWallet, $ledger->balance($customerId, $storeId), 'Partial cancellation releases only uninvoiced wallet tender');
    $availableBaseline = $ledger->available($customerId, $storeId);
    $firstCart = $makeQuote();
    $secondCart = $makeQuote();
    $selection->choose($firstCart, 6000);
    $selection->choose($secondCart, 6000);
    $firstCart->reserveOrderId();
    $secondCart->reserveOrderId();
    $selection->reserve($firstCart, 6000, 6000);
    $check($availableBaseline - 6000, $ledger->available($customerId, $storeId), 'A hold removes funds from available balance');
    $selection->release((int) $firstCart->getId());
    $check($availableBaseline, $ledger->available($customerId, $storeId), 'Abandoned uncommitted hold can be recovered');
    $failed = $makeQuote();
    $selection->choose($failed, 1000);
    $plugin = $om->get(\LoyaltyEngage\LoyaltyShop\Plugin\Wallet\Submit::class);
    try {
        $plugin->aroundSubmit($om->get(\Magento\Quote\Model\QuoteManagement::class),
            static function () { throw new \Magento\Framework\Exception\LocalizedException(__('Simulated payment failure')); }, $failed);
        throw new RuntimeException('Expected a simulated failure.');
    } catch (\Magento\Framework\Exception\LocalizedException) {
        $check('selected', $selection->row((int) $failed->getId())['status'], 'Failed placement releases its hold');
        $check($availableBaseline, $ledger->available($customerId, $storeId), 'Failed placement does not debit money');
    }
    $selection->reserve($firstCart, 6000, 6000);
    try {
        $selection->reserve($secondCart, 6000, 6000);
        throw new RuntimeException('A competing cart overspent available funds.');
    } catch (\Magento\Framework\Exception\LocalizedException) {
        $check($availableBaseline - 6000, $ledger->available($customerId, $storeId), 'A competing reservation is rejected atomically');
        // Magento marks the enclosing test transaction rollback-only after this
        // expected nested failure. No further writes until the outer rollback.
    }
} finally {
    $db->rollBack();
    $guard->release();
}
$check($before, $ledger->balance($customerId, $storeId), 'Financial fixtures rolled back');
$check($beforeAvailable, $ledger->available($customerId, $storeId), 'Reservation fixtures rolled back');
echo json_encode(['store' => $storeCode, 'wallet_payment_checks' => $count, 'all_passed' => true, 'fixtures_rolled_back' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
