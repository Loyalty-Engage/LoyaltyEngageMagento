<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;

class PurchaseObserver implements ObserverInterface
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

        if (!$this->helper->isPurchaseExportEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();

        if ($originalStatus === $currentStatus || $currentStatus !== 'complete') {
            return;
        }

        $email = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();
        $orderDate = (new \DateTime($order->getCreatedAt()))->format('c');

        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'sku' => $item->getSku(),
                'price' => number_format((float) $item->getPrice(), 2, '.', ''),
                'quantity' => (int) $item->getQtyOrdered()
            ];
        }

        $payload = [
            [
                'event' => 'Purchase',
                'identifier' => $email,
                'orderId' => $orderId,
                'orderDate' => $orderDate,
                'products' => $products
            ]
        ];

        try {
            $this->publisher->publish(
                'loyaltyshop.purchase_event',
                json_encode($payload)
            );

            $this->helper->log(
                'info',
                'LoyaltyShop',
                'PurchaseEventPublished',
                'Purchase payload published to queue.',
                [
                    'email' => $email,
                    'order_id' => $orderId,
                    'order_date' => $orderDate,
                    'products_count' => count($products),
                    'products' => $products,
                    'payload' => $payload[0]
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'PurchaseEventError',
                'Failed to queue Purchase event.',
                [
                    'error_message' => $e->getMessage(),
                    'email' => $email,
                    'order_id' => $orderId
                ]
            );
        }
    }
}