<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyOrderItems;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;

class FreeProductPurchaseObserver implements ObserverInterface
{
    public function __construct(
        private Data $helper, private PublisherInterface $publisher,
        private OrderExportEligibility $exportEligibility, private LoyaltyOrderItems $loyaltyItems
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId() || $order->getData('loyalty_order_place')) {
            return;
        }
        $storeId = (int) $order->getStoreId();
        if ($order->getOrigData('status') === $order->getStatus()) {
            return;
        }
        if (!$this->helper->isLoyaltyEngageEnabled($storeId)
            || !in_array($order->getStatus(), $this->helper->getPurchaseOrderStatuses($storeId), true)
            || !$this->exportEligibility->evaluate($order)['eligible']) {
            return;
        }
        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $quantity = (float) $item->getQtyOrdered() - (float) $item->getQtyCanceled();
            if ($quantity > 0 && $this->loyaltyItems->isRedemption($item)) {
                $products[] = ['sku' => $item->getSku(), 'quantity' => $quantity];
            }
        }
        if ($products) {
            $this->publisher->publish('loyaltyshop.free_product_purchase_event', json_encode([
                'email' => $order->getCustomerEmail(), 'orderId' => $order->getIncrementId(),
                'store_id' => $storeId, 'products' => $products,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
