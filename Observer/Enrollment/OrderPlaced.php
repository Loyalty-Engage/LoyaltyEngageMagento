<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Choice, Enroll};

class OrderPlaced implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(private Config $config, private Choice $choice, private Enroll $enroll) {}

    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof \Magento\Sales\Model\Order || !$order->getId() || !$order->getQuoteId()) { return; }
        // Consent belongs to placement, not to a later status edit on an old order.
        if ($order->getOrigData('entity_id')) { return; }
        $storeId = (int) $order->getStoreId();
        if ($this->config->mode($storeId) !== 'required') { return; }
        $choice = $this->choice->get((int) $order->getQuoteId(), $storeId);
        if (($choice['mode'] ?? '') !== 'required' || empty($choice['selected'])) { return; }
        $email = trim((string) $order->getCustomerEmail());
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return; }
        $this->enroll->execute($email, $storeId, 'checkout', [
            'consent_label' => $choice['label'], 'consent_at' => $choice['updated_at'],
            'order_id' => $order->getIncrementId(),
        ]);
    }
}
