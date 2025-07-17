<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

class QuoteItemQtyValidatorPlugin
{
    /**
     * Prevent quantity changes for loyalty products
     *
     * @param QuantityValidator $subject
     * @param \Closure $proceed
     * @param Observer $observer
     * @return void
     */
    public function aroundValidate(QuantityValidator $subject, \Closure $proceed, Observer $observer)
    {
        $quoteItem = $observer->getEvent()->getItem();
        
        // Only check for confirmed loyalty products using reliable detection
        if ($quoteItem && $this->isConfirmedLoyaltyProduct($quoteItem)) {
            // Get the original quantity
            $originalQty = $quoteItem->getOrigData('qty');
            
            // If the quantity was changed, revert it back
            if ($originalQty && $originalQty != $quoteItem->getQty()) {
                $quoteItem->setQty($originalQty);
            }
            
            // Skip further validation for loyalty products
            return;
        }
        
        // For regular products, proceed with normal validation
        return $proceed($observer);
    }

    /**
     * Reliable loyalty product detection - only confirmed loyalty products
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return bool
     */
    private function isConfirmedLoyaltyProduct($quoteItem): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $quoteItem->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }

        // Method 2: Check item data directly (secondary check)
        $loyaltyData = $quoteItem->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $quoteItem->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = @unserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (isset($option['label']) && $option['label'] === 'loyalty_locked_qty' && 
                        isset($option['value']) && $option['value'] === '1') {
                        return true;
                    }
                }
            }
        }

        // Not a confirmed loyalty product
        return false;
    }
}
