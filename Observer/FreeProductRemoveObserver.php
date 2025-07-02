<?php

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\MessageQueue\PublisherInterface;

class FreeProductRemoveObserver implements ObserverInterface
{
    protected $helper;
    protected $logger;
    protected $customerSession;
    protected $publisher;

    public function __construct(
        Data $helper,
        LoggerInterface $logger,
        CustomerSession $customerSession,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer)
    {
        $item = $observer->getEvent()->getQuoteItem();
        if (!$item) {
            return;
        }

        if ((float)$item->getPrice() !== 0.0) {
            $this->logger->info("[LoyaltyShop] Removed item is not free, skipping.");
            return;
        }

        $sku = $item->getSku();
        $qty = (int)$item->getQty();

        $email = $this->customerSession->getCustomer() ? $this->customerSession->getCustomer()->getEmail() : null;
        if (!$email) {
            $this->logger->warning("[LoyaltyShop] Cannot determine customer email for free product removal.");
            return;
        }

        $payload = [
            'email' => $email,
            'sku' => $sku,
            'quantity' => $qty
        ];

        try {
            $this->publisher->publish('loyaltyshop.free_product_remove_event', json_encode($payload));
            $this->logger->info("[LoyaltyShop] Free product remove payload published to queue.", $payload);
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Failed to queue free product remove event: " . $e->getMessage());
        }
    }
}
