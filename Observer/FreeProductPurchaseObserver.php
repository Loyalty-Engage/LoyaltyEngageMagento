<?php

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;

class FreeProductPurchaseObserver implements ObserverInterface
{
    protected $helper;
    protected $logger;
    protected $publisher;

    public function __construct(
        Data $helper,
        LoggerInterface $logger,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();

        // Only trigger when status has just changed to 'complete'
        if ($originalStatus === $currentStatus || $currentStatus !== 'complete') {
            return;
        }

        $freeProducts = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ((float)$item->getPrice() === 0.0) {
                $freeProducts[] = [
                    'sku' => $item->getSku(),
                    'quantity' => (int)$item->getQtyOrdered()
                ];
            }
        }

        if (empty($freeProducts)) {
            $this->logger->info("[LoyaltyShop] No free products found in order {$order->getIncrementId()}.");
            return;
        }

        $email = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();
        $payload = [
            'email' => $email,
            'orderId' => $orderId,
            'products' => $freeProducts
        ];

        try {
            $this->publisher->publish('loyaltyshop.free_product_purchase_event', json_encode($payload));
            $this->logger->info("[LoyaltyShop] Free product purchase payload published to queue.", $payload);
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Failed to queue free product purchase event: " . $e->getMessage());
        }
    }
}
