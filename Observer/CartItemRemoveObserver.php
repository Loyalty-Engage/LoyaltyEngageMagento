<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\CartItemRemoveProcessor;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** Compatibility wrapper for merchants that registered the legacy observer themselves. */
class CartItemRemoveObserver implements ObserverInterface
{
    public function __construct(private CartItemRemoveProcessor $processor)
    {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getQuoteItem() ?: $observer->getEvent()->getItem();
        if ($item && $item->getQuote()) {
            $this->processor->process($item->getQuote(), $item);
        }
    }
}
