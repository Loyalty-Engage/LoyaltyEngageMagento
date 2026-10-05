<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderData;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class PersistOrderDataObserver implements ObserverInterface
{
    public function __construct(private OrderData $orderData, private OrderSource $source)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId()) {
            return;
        }
        $rawSource = (string) $order->getData(OrderData::CAPTURED_SOURCE);
        if ($rawSource !== '') {
            $this->orderData->saveSource($order, $this->source->normalize($rawSource));
            $order->unsetData(OrderData::CAPTURED_SOURCE);
        }
    }
}
