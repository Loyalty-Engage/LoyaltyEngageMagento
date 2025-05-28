<?php

namespace LoyaltyEngage\LoyaltyShop\ViewModel;

class CartItemHelper implements \Magento\Framework\View\Element\Block\ArgumentInterface
{
    /**
     * Check if the item quantity should be locked
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    public function isQtyLocked($item): bool
    {
        // Preferred method if loyalty_locked_qty is stored directly
        if ($item->getData('loyalty_locked_qty') === '1' || $item->getData('loyalty_locked_qty') === 1) {
            return true;
        }

        // Check if loyalty_locked_qty option is set
        $loyaltyLockedQty = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyLockedQty) {
            return true;
        }

        // Fallback to checking additional_options
        $options = $item->getOptionByCode('additional_options');
        if ($options) {
            $value = @unserialize($options->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if ($option['label'] === 'loyalty_locked_qty' && $option['value'] === '1') {
                        return true;
                    }
                }
            }
        }

        // Lock quantity if item price is 0
        return (float)$item->getPrice() == 0;
    }
}
