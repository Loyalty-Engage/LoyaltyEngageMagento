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
    public function __construct(
        private Data $helper,
        private PublisherInterface $publisher
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!$this->helper->isLoyaltyEngageEnabled($storeId)) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus  = $order->getStatus();

        $triggerStatuses = ['complete', 'accepted'];
        if ($originalStatus === $currentStatus || !in_array($currentStatus, $triggerStatuses, true)) {
            return;
        }

        $freeProducts = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ((float) $item->getPrice() === 0.0) {
                $freeProducts[] = [
                    'sku'      => $item->getSku(),
                    'quantity' => (int) $item->getQtyOrdered()
                ];
            }
        }

        if (empty($freeProducts)) {
            $this->helper->log(
                'info',
                'LoyaltyShop',
                'FreeProductPurchase',
                sprintf('No free products found in order %s - skipping flow.', $order->getIncrementId()),
                ['order_id' => $order->getIncrementId()]
            );
            return;
        }

        $email   = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();

        $payload = [
            'email'    => $email,
            'orderId'  => $orderId,
            'store_id' => $storeId,
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
                'Free product purchase flow triggered',
                [
                    'trigger_reason'      => sprintf('Order status changed to %s', $currentStatus),
                    'email'               => $email,
                    'order_id'            => $orderId,
                    'previous_status'     => $originalStatus,
                    'current_status'      => $currentStatus,
                    'free_products_count' => count($freeProducts),
                    'free_products'       => $freeProducts,
                    'payload'             => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'FreeProductPurchaseError',
                'Queue publish failed',
                [
                    'error_message' => $e->getMessage(),
                    'email'         => $email,
                    'order_id'      => $orderId,
                    'free_products' => $freeProducts
                ]
            );
        }
    }
}
