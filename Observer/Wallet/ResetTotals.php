<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ResetTotals implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $observer->getEvent()->getQuote()->setData(Selection::AMOUNT, 0);
    }
}
