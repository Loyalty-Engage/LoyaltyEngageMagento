<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Framework\View\Result\Page;
use Magento\Framework\App\RequestInterface;

class ThemeLayoutPlugin
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * Handles that should have Hyvä-specific variants
     * ONLY cart-related handles to avoid menu/navigation conflicts
     * Removed catalog_category_view and catalog_product_view to prevent ESI cache issues
     */
    private const LOYALTY_SPECIFIC_HANDLES = [
        'checkout_cart_index',
        'checkout_cart_item_renderers'
    ];

    /**
     * @param RequestInterface $request
     */
    public function __construct(
        RequestInterface $request
    ) {
        $this->request = $request;
    }

    /**
     * Add Hyvä-specific layout handles for loyalty cart functionality only
     * 
     * This plugin is specifically designed for Hyvä theme installations.
     * It ONLY adds Hyvä layout handles for cart-related pages and completely
     * skips execution during ESI requests to prevent menu interference.
     *
     * @param Page $subject
     * @param Page $result
     * @return Page
     */
    public function afterAddDefaultHandle(
        Page $subject,
        Page $result
    ) {
        // Skip plugin execution entirely during ESI requests to prevent menu interference
        if ($this->isEsiRequest()) {
            return $result;
        }

        // Get the current handles
        $update = $subject->getLayout()->getUpdate();
        $handles = $update->getHandles();
        
        // Only add Hyvä-specific handles for cart-related pages
        // This preserves loyalty functionality while avoiding ESI conflicts
        foreach ($handles as $handle) {
            // Only add hyva/ prefix for cart-specific handles
            if (in_array($handle, self::LOYALTY_SPECIFIC_HANDLES)) {
                $hyvaHandle = 'hyva/' . $handle;
                $update->addHandle($hyvaHandle);
            }
        }
        
        return $result;
    }

    /**
     * Check if the current request is an ESI request
     * ESI requests are used by Varnish for menu and other cached blocks
     *
     * @return bool
     */
    private function isEsiRequest(): bool
    {
        $requestUri = $this->request->getRequestUri();
        
        // Check if this is an ESI request (page_cache/block/esi)
        if (strpos($requestUri, '/page_cache/block/esi/') !== false) {
            return true;
        }
        
        // Additional ESI patterns that might be used
        if (strpos($requestUri, '/page_cache/') !== false && strpos($requestUri, '/esi/') !== false) {
            return true;
        }
        
        return false;
    }
}
