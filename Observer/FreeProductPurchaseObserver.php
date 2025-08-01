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
        if ($this->helper->isLoyaltyEngageEnabled()) {
            $order = $observer->getEvent()->getOrder();
            if (!$order || !$order instanceof Order) {
                return;
            }

            $originalStatus = $order->getOrigData('status');
            $currentStatus = $order->getStatus();

            // Trigger when status has just changed to 'complete' or 'accepted'
            $triggerStatuses = ['complete', 'accepted'];
            if ($originalStatus === $currentStatus || !in_array($currentStatus, $triggerStatuses)) {
                return;
            }

            $freeProducts = [];
            foreach ($order->getAllVisibleItems() as $item) {
                if ((float) $item->getPrice() === 0.0) {
                    $freeProducts[] = [
                        'sku' => $item->getSku(),
                        'quantity' => (int) $item->getQtyOrdered()
                    ];
                }
            }

            if (empty($freeProducts)) {
                $this->logger->info("[LoyaltyShop] No free products found in order {$order->getIncrementId()} - skipping free product purchase flow.");
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
                $this->logger->info("[LoyaltyShop] Free Product Purchase Flow Triggered", [
                    'trigger_reason' => "Order status changed to {$currentStatus} with free products",
                    'email' => $email,
                    'orderId' => $orderId,
                    'current_status' => $currentStatus,
                    'previous_status' => $originalStatus,
                    'free_products_count' => count($freeProducts),
                    'free_products' => $freeProducts,
                    'queue_message' => 'Published to loyaltyshop.free_product_purchase_event',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } catch (\Exception $e) {
                $this->logger->error("[LoyaltyShop] Free Product Purchase Queue Error", [
                    'error_message' => $e->getMessage(),
                    'email' => $email,
                    'orderId' => $orderId,
                    'free_products' => $freeProducts,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
}
