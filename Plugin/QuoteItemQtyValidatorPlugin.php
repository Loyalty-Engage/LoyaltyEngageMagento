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
     * CONSERVATIVE APPROACH: Only act on 100% confirmed loyalty products
     *
     * @param QuantityValidator $subject
     * @param Observer $observer
     * @return void
     */
    public function beforeValidate(QuantityValidator $subject, Observer $observer)
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $quoteItem = $observer->getEvent()->getItem();
        
        // Early exit if no quote item
        if (!$quoteItem) {
            return;
        }

        // ONLY process if this is a 100% confirmed loyalty product
        // Use the most restrictive detection to avoid false positives
        if (!$this->isConfirmedLoyaltyProduct($quoteItem)) {
            return; // Leave regular products completely untouched
        }

        // Additional safety check: only protect if original qty exists and is different
        $originalQty = $quoteItem->getOrigData('qty');
        $currentQty = $quoteItem->getQty();
        
        if ($originalQty && $originalQty != $currentQty && $originalQty > 0) {
            // Only revert if the change seems unintentional (not a legitimate update)
            // Check if this is during a cart update operation
            $request = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\RequestInterface::class);
            
            // Skip protection during legitimate cart updates
            if ($request->getActionName() === 'updatePost' || 
                $request->getParam('update_cart_action') || 
                $request->getParam('cart')) {
                return; // Allow legitimate cart updates
            }
            
            // Protect loyalty product quantity
            $quoteItem->setQty($originalQty);

            // Log the protection action
            error_log(sprintf(
                '[LoyaltyShop] Quantity protection: Reverted qty for loyalty product %s from %s to %s',
                $quoteItem->getSku(),
                $currentQty,
                $originalQty
            ));
        }
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
