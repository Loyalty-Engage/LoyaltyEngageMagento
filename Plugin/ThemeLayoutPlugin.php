<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Framework\View\Result\Page;
use LoyaltyEngage\LoyaltyShop\Helper\Data;

class ThemeLayoutPlugin
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * @param Data $helper
     */
    public function __construct(
        Data $helper
    ) {
        $this->helper = $helper;
    }

    /**
     * Add Hyvä-specific layout handles if Hyvä theme is active
     *
     * @param Page $subject
     * @param Page $result
     * @return Page
     */
    public function afterAddDefaultHandle(
        Page $subject,
        Page $result
    ) {
        if ($this->helper->isHyvaTheme()) {
            // Get the current handles
            $update = $subject->getLayout()->getUpdate();
            $handles = $update->getHandles();
            
            // Add Hyvä-specific handles for each existing handle
            foreach ($handles as $handle) {
                // Add hyva/handle_name for each handle
                $hyvaHandle = 'hyva/' . $handle;
                $update->addHandle($hyvaHandle);
            }
        }
        
        return $result;
    }
}
