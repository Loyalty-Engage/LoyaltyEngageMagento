<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

class QuoteItemQtyValidatorPlugin
{
    /**
     * QuoteItemQtyValidatorPlugin construct
     *
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * Prevent quantity changes for loyalty products (before validation)
     * This approach is less intrusive and doesn't interfere with regular product processing
     *
     * @param QuantityValidator $subject
     * @param Observer $observer
     * @return void
     */
    public function beforeValidate(QuantityValidator $subject, Observer $observer)
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            $quoteItem = $observer->getEvent()->getItem();

            // Only process confirmed loyalty products - leave regular products completely untouched
            if ($quoteItem && $this->isConfirmedLoyaltyProduct($quoteItem)) {
                // Get the original quantity for loyalty products
                $originalQty = $quoteItem->getOrigData('qty');

                // If the quantity was changed, revert it back to protect loyalty product quantities
                if ($originalQty && $originalQty != $quoteItem->getQty()) {
                    $quoteItem->setQty($originalQty);

                    // Log the quantity protection action
                    error_log(sprintf(
                        '[LoyaltyShop] Quantity protection: Reverted qty for loyalty product %s from %s to %s',
                        $quoteItem->getSku(),
                        $quoteItem->getQty(),
                        $originalQty
                    ));
                }
            }
        }
        // Always allow normal Magento validation to proceed for ALL products
        // No return value needed for beforeValidate - this ensures normal processing continues
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
                    if (
                        isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                        isset($option['value']) && $option['value'] === '1'
                    ) {
                        return true;
                    }
                }
            }
        }

        // Not a confirmed loyalty product
        return false;
    }
}
