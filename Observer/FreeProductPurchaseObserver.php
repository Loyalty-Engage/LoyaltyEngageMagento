<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;

class FreeProductPurchaseObserver implements ObserverInterface
{
    protected $helper;
    protected $publisher;

    public function __construct(
        Data $helper,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer)
    {
        if (!$this->helper->isLoyaltyEngageEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();

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
            $this->helper->log(
                'info',
                'LoyaltyShop',
                'FreeProductPurchase',
                "No free products found in order {$order->getIncrementId()} - skipping flow.",
                [
                    'order_id' => $order->getIncrementId()
                ]
            );
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
            $this->publisher->publish(
                'loyaltyshop.free_product_purchase_event',
                json_encode($payload)
            );

            $this->helper->log(
                'info',
                'LoyaltyShop',
                'FreeProductPurchaseTriggered',
                "Free product purchase flow triggered",
                [
                    'trigger_reason' => "Order status changed to {$currentStatus}",
                    'email' => $email,
                    'order_id' => $orderId,
                    'previous_status' => $originalStatus,
                    'current_status' => $currentStatus,
                    'free_products_count' => count($freeProducts),
                    'free_products' => $freeProducts,
                    'payload' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'FreeProductPurchaseError',
                "Queue publish failed",
                [
                    'error_message' => $e->getMessage(),
                    'email' => $email,
                    'order_id' => $orderId,
                    'free_products' => $freeProducts
                ]
            );
        }
    }
}
