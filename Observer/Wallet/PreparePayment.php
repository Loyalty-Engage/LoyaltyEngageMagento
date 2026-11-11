<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PreparePayment implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $quote = $event->getQuote();
        if ($quote && is_array($quote->getData(Selection::HOLD))) {
            $event->getOrder()->setData(Selection::HOLD, $quote->getData(Selection::HOLD));
        }
    }
}
