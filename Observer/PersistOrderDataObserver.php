<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderData;
use LoyaltyEngage\LoyaltyShop\Model\OrderItemData;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class PersistOrderDataObserver implements ObserverInterface
{
    public function __construct(private OrderData $orderData, private OrderItemData $itemData, private OrderSource $source)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId()) {
            return;
        }
        $rawSource = (string) $order->getData('loyalty_order_source');
        if ($rawSource !== '') {
            $this->orderData->saveSource($order, $this->source->normalize($rawSource));
        }
        foreach ($order->getAllItems() as $item) {
            if ($item->getId()) {
                $options = $item->getProductOptions() ?: [];
                if (!empty($options['loyalty_locked_qty'])) {
                    $this->itemData->save($item, true);
                }
            }
        }
    }
}
