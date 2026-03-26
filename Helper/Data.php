<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_EXPORT = 'loyalty/export/';
    const XML_PATH_GENERAL = 'loyalty/general/';
    const XML_PATH_SHIPPING = 'loyalty/shipping/';

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param Context $context
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

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
     * Note: This value is stored encrypted in the database
     *
     * @return string|null
     */
    public function getClientId(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'tenant_id',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return null;
        }
        
        // Decrypt the value - Magento's Encrypted backend stores values encrypted
        return $this->encryptor->decrypt($value);
    }

    /**
     * Get Client Secret (Bearer Token) from config
     * Note: This value is stored encrypted in the database
     *
     * @return string|null
     */
    public function getClientSecret(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'bearer_token',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return null;
        }
        
        // Decrypt the value - Magento's Encrypted backend stores values encrypted
        return $this->encryptor->decrypt($value);
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

    /**
     * Get Queue Processing Frequency (cron schedule)
     *
     * @return string
     */
    public function getQueueProcessingFrequency(): string
    {
        $frequency = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'queue_processing_frequency',
            ScopeInterface::SCOPE_STORE
        );
        
        return $frequency ?: '*/5 * * * *'; // Default: Every 5 minutes
    }

    /**
     * Get Minimum Order Value for Loyalty Products
     *
     * @return float
     */
    public function getMinimumOrderValueForLoyalty(): float
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value',
            ScopeInterface::SCOPE_STORE
        );
        
        return $value ? (float)$value : 0.0;
    }

    /**
     * Check if minimum order value restriction is enabled
     *
     * @return bool
     */
    public function isMinimumOrderValueEnabled(): bool
    {
        return $this->getMinimumOrderValueForLoyalty() > 0;
    }

    /**
     * Get Minimum Order Value Error Message (with placeholders)
     *
     * @return string
     */
    public function getMinimumOrderValueMessage(): string
    {
        $message = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_message',
            ScopeInterface::SCOPE_STORE
        );
        
        return $message ?: 'Your cart subtotal must be at least €{{minimum}} to add loyalty products. Current subtotal: €{{current}}';
    }

    /**
     * Get formatted Minimum Order Value Error Message with actual values
     *
     * @param float $minimumValue
     * @param float $currentValue
     * @return string
     */
    public function getFormattedMinimumOrderValueMessage(float $minimumValue, float $currentValue): string
    {
        $message = $this->getMinimumOrderValueMessage();
        
        return str_replace(
            ['{{minimum}}', '{{current}}'],
            [number_format($minimumValue, 2), number_format($currentValue, 2)],
            $message
        );
    }

    /**
     * Get Minimum Order Value Message Bar Color
     *
     * @return string
     */
    public function getMinimumOrderValueBarColor(): string
    {
        $color = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_bar_color',
            ScopeInterface::SCOPE_STORE
        );
        
        return $color ?: '#e74c3c';
    }

    /**
     * Get Minimum Order Value Message Text Color
     *
     * @return string
     */
    public function getMinimumOrderValueTextColor(): string
    {
        $color = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_text_color',
            ScopeInterface::SCOPE_STORE
        );
        
        return $color ?: '#ffffff';
    }
}
