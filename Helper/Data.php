<?php
namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_EXPORT = 'loyalty/export/';
    const XML_PATH_GENERAL = 'loyalty/general/';
    const XML_PATH_SHIPPING = 'loyalty/shipping/';

    /**
     * Check if LoyaltyEngageEnabled is enabled
     *
     * @return bool
     */
    public function isLoyaltyEngageEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'module_enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Return Export is enabled
     *
     * @return bool
     */
    public function isReturnExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'return_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Purchase Export is enabled
     *
     * @return bool
     */
    public function isPurchaseExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'purchase_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Client ID (Tenant ID) from config
     *
     * @return string|null
     */
    public function getClientId(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'tenant_id',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Client Secret (Bearer Token) from config
     *
     * @return string|null
     */
    public function getClientSecret(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'bearer_token',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get API URL from config
     *
     * @return string|null
     */
    public function getApiUrl(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'loyalty_api_url',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Review Export is enabled
     *
     * @return bool
     */
    public function isReviewExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'review_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Free Shipping for Loyalty Tiers is enabled
     *
     * @return bool
     */
    public function isFreeShippingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHIPPING . 'free_shipping_enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Free Shipping Tiers (semicolon-separated)
     *
     * @return string|null
     */
    public function getFreeShippingTiers(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_SHIPPING . 'free_shipping_tiers',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Free Shipping Tiers as array
     *
     * @return array
     */
    public function getFreeShippingTiersArray(): array
    {
        $tiers = $this->getFreeShippingTiers();
        if (empty($tiers)) {
            return [];
        }
        
        return array_map('trim', explode(';', $tiers));
    }

    /**
     * Get Tier Cache Duration in seconds
     *
     * @return int
     */
    public function getTierCacheDuration(): int
    {
        $duration = $this->scopeConfig->getValue(
            self::XML_PATH_SHIPPING . 'tier_cache_duration',
            ScopeInterface::SCOPE_STORE
        );
        
        return $duration ? (int)$duration : 600; // Default 10 minutes
    }
}
