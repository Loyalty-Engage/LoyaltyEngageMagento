<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Accrual;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;

class Refunded implements ObserverInterface
{
    public function __construct(private Accrual $accrual)
    {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if ($creditmemo instanceof Creditmemo) {
            $this->accrual->refunded($creditmemo);
        }
    }
}
