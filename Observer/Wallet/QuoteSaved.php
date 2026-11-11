<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class QuoteSaved implements ObserverInterface
{
    public function __construct(private Selection $selection)
    {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if ($quote instanceof \Magento\Quote\Model\Quote) {
            $this->selection->savePreview($quote);
        }
    }
}
