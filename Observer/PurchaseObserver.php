<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\CouponOwnership;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;

class PurchaseObserver implements ObserverInterface
{
    public function __construct(
        private Data $helper,
        private PublisherInterface $publisher,
        private OrderExportEligibility $exportEligibility,
        private CouponOwnership $coupons
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId()) {
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
        if ($this->helper->isPurchaseExportEnabled($storeId) && !$order->getData('loyalty_purchase_exported')) {
            $products = [];
            foreach ($order->getAllItems() as $item) {
                if (($item->getParentItemId() && !$item->isChildrenCalculated())
                    || ($item->getHasChildren() && $item->isChildrenCalculated())) {
                    continue;
                }
                $quantity = (float) $item->getQtyOrdered() - (float) $item->getQtyCanceled();
                if ($quantity <= 0) {
                    continue;
                }
                $products[] = [
                    'sku' => $item->getSku(),
                    'price' => number_format((float) $item->getPrice(), 2, '.', ''),
                    'quantity' => $quantity,
                ];
            }
            if ($products) {
                $this->publisher->publish('loyaltyshop.purchase_event', json_encode([[
                    'event' => 'Purchase', 'identifier' => $order->getCustomerEmail(),
                    'orderId' => $order->getIncrementId(), 'store_id' => $storeId,
                    'orderDate' => (new \DateTimeImmutable($order->getCreatedAt(), new \DateTimeZone('UTC')))->format(DATE_ATOM),
                    'products' => $products,
                ]], JSON_THROW_ON_ERROR));
            }
        }
        $code = (string) $order->getCouponCode();
        if ($this->coupons->isManaged($code, (int) $order->getStore()->getWebsiteId())) {
            $this->publisher->publish('loyaltyshop.redeem_discount_event', json_encode([
                'discount_code' => $code, 'identifier' => $this->helper->hashEmail($order->getCustomerEmail()),
                'order_id' => $order->getIncrementId(), 'store_id' => $storeId,
            ], JSON_THROW_ON_ERROR));
        }
    }
}
