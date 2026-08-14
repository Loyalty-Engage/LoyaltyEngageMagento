<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;

class PurchaseObserver implements ObserverInterface
{
    public function __construct(
        private Data $helper,
        private PublisherInterface $publisher,
        private LoyaltyengageCart $loyaltyCart
    ) {
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!$this->helper->isLoyaltyEngageEnabled($storeId)) {
            return;
        }

        if (!$this->helper->isPurchaseExportEnabled($storeId)) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();
        $triggerStatus = $this->helper->getPurchaseOrderStatus($storeId);

        if ($originalStatus === $currentStatus || $currentStatus !== $triggerStatus) {
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
                'store_id' => $storeId,
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

        // Redeem voucher in LoyaltyEngage when order reaches trigger status — via queue (async)
        $couponCode = $order->getCouponCode();
        if (!empty($couponCode)) {
            try {
                $emailHash = $this->helper->hashEmail($email);

                $redeemPayload = [
                    'discount_code' => $couponCode,
                    'identifier'    => $emailHash,
                    'order_id'      => $orderId,
                    'store_id'      => $storeId
                ];

                $this->publisher->publish(
                    'loyaltyshop.redeem_discount_event',
                    json_encode($redeemPayload)
                );

                $this->helper->log(
                    'info',
                    'LoyaltyShop',
                    'VoucherRedeemQueued',
                    'Voucher redeem event published to queue.',
                    [
                        'order_id'    => $orderId,
                        'coupon_code' => $couponCode
                    ]
                );
            } catch (\Exception $e) {
                $this->helper->log(
                    'error',
                    'LoyaltyShop',
                    'VoucherRedeemQueueError',
                    'Failed to publish voucher redeem event to queue.',
                    [
                        'error_message' => $e->getMessage(),
                        'order_id'      => $orderId,
                        'coupon_code'   => $couponCode
                    ]
                );
            }
        }
    }
}
