<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Framework\View\Result\Page;

class ThemeLayoutPlugin
{
    /**
     * Add Hyvä-specific layout handles
     * 
     * This plugin is specifically designed for Hyvä theme installations.
     * Since this is a Hyvä-only plugin, we always add Hyvä layout handles
     * without runtime theme detection to ensure cache compatibility.
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
        
        // Add Hyvä-specific handles for each existing handle
        foreach ($handles as $handle) {
            // Add hyva/handle_name for each handle
            $hyvaHandle = 'hyva/' . $handle;
            $update->addHandle($hyvaHandle);
        }
        
        return $result;
    }
}
