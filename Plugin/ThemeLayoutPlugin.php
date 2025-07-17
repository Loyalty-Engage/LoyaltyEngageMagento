<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Framework\View\Result\Page;

class ThemeLayoutPlugin
{
    /**
     * Handles that should have Hyvä-specific variants
     * Only these handles will get the "hyva/" prefix to avoid conflicts with core menu functionality
     */
    private const LOYALTY_SPECIFIC_HANDLES = [
        'catalog_category_view',
        'catalog_product_view',
        'checkout_cart_index',
        'checkout_cart_item_renderers'
    ];

    /**
     * Add Hyvä-specific layout handles for loyalty module functionality only
     * 
     * This plugin is specifically designed for Hyvä theme installations.
     * It only adds Hyvä layout handles for specific pages that use loyalty functionality
     * to ensure cache compatibility and prevent menu conflicts.
     *
     * @param Page $subject
     * @param Page $result
     * @return Page
     */
    public function afterAddDefaultHandle(
        Page $subject,
        Page $result
    ) {
        // Get the current handles
        $update = $subject->getLayout()->getUpdate();
        $handles = $update->getHandles();
        
        // Only add Hyvä-specific handles for loyalty-related pages
        foreach ($handles as $handle) {
            // Only add hyva/ prefix for handles that actually need loyalty functionality
            if (in_array($handle, self::LOYALTY_SPECIFIC_HANDLES)) {
                $hyvaHandle = 'hyva/' . $handle;
                $update->addHandle($hyvaHandle);
            }
        }
        
        return $result;
    }
}
