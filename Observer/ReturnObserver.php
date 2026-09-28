<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order\Creditmemo;

class ReturnObserver implements ObserverInterface
{
    public function __construct(private Data $helper, private PublisherInterface $publisher, private PurchaseHistory $history)
    {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo instanceof Creditmemo || !$creditmemo->getId()
            || (int) $creditmemo->getState() !== Creditmemo::STATE_REFUNDED
            || $creditmemo->getData('loyalty_return_exported')) {
            return;
        }
        $order = $creditmemo->getOrder();
        $storeId = (int) $order->getStoreId();
        if (!$this->helper->isLoyaltyEngageEnabled($storeId)
            || !$this->helper->isReturnExportEnabled($storeId) || !$this->history->canExportReturn($order)) {
            return;
        }
        $products = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if (($orderItem && $orderItem->getParentItemId() && !$orderItem->isChildrenCalculated())
                || ($orderItem && $orderItem->getHasChildren() && $orderItem->isChildrenCalculated())
                || (float) $item->getQty() <= 0) {
                continue;
            }
            $products[] = ['sku' => $item->getSku(), 'price' => number_format((float) $item->getPrice(), 2, '.', ''),
                'quantity' => (float) $item->getQty()];
        }
        if ($products) {
            $this->publisher->publish('loyaltyshop.return_event', json_encode([[
                'event' => 'Return', 'identifier' => $order->getCustomerEmail(), 'store_id' => $storeId,
                'orderId' => $order->getIncrementId(), 'creditmemo_id' => (int) $creditmemo->getId(),
                'orderDate' => (new \DateTimeImmutable($creditmemo->getCreatedAt(), new \DateTimeZone('UTC')))->format(DATE_ATOM),
                'products' => $products,
            ]], JSON_THROW_ON_ERROR));
        }
    }
}
