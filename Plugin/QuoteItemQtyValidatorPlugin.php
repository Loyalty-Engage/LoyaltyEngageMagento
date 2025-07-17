<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

class QuoteItemQtyValidatorPlugin
{
    /**
     * Prevent quantity changes for free products
     *
     * @param QuantityValidator $subject
     * @param \Closure $proceed
     * @param Observer $observer
     * @return void
     */
    public function aroundValidate(QuantityValidator $subject, \Closure $proceed, Observer $observer)
    {
        $quoteItem = $observer->getEvent()->getItem();
        
        // Check if this is a free product or has loyalty_locked_qty flag
        if ($quoteItem && ((float)$quoteItem->getPrice() == 0 || $quoteItem->getOptionByCode('loyalty_locked_qty'))) {
            // Get the original quantity
            $originalQty = $quoteItem->getOrigData('qty');
            
            // If the quantity was changed, revert it back
            if ($originalQty && $originalQty != $quoteItem->getQty()) {
                $quoteItem->setQty($originalQty);
            }
            
            // Skip further validation
            return;
        }
        
        // For regular products, proceed with normal validation
        return $proceed($observer);
    }
}
