<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class CaptureOrderSourceObserver implements ObserverInterface
{
    public function __construct(
        private State $appState,
        private OrderSource $orderSource
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order) {
            return;
        }

        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (\Throwable $exception) {
            $areaCode = '';
        }

        $order->setData('loyalty_order_source', $this->orderSource->fromAreaCode($areaCode));
    }
}
