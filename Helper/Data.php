<?php
namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_EXPORT = 'loyalty/export/';
    const XML_PATH_GENERAL = 'loyalty/general/';
    const XML_PATH_FREE_SHIPPING = 'loyalty/free_shipping/';
    const XML_PATH_MESSAGES = 'loyalty/messages/';
    
    /**
     * @param \Magento\Framework\App\Helper\Context $context
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        protected DesignInterface $design,
        protected ThemeProviderInterface $themeProvider
    ) {
        parent::__construct($context);
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
     * Get minimum review characters required for export
     *
     * @return int
     */
    public function getReviewMinCharacters(): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_EXPORT . 'review_min_characters',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Review Debug Logging is enabled
     *
     * @return bool
     */
    public function isReviewDebugLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'review_debug_logging',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get loyalty levels for free shipping
     *
     * @return array
     */
    public function getFreeShippingLoyaltyLevels(): array
    {
        $levels = $this->scopeConfig->getValue(
            self::XML_PATH_FREE_SHIPPING . 'loyalty_levels',
            ScopeInterface::SCOPE_STORE
        );

        if (!$levels) {
            return [];
        }

        return array_map('trim', explode(';', $levels));
    }

    /**
     * Get success message text
     *
     * @return string
     */
    public function getSuccessMessage(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'success_message',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Product successfully added to cart!';
    }

    /**
     * Get success message background color
     *
     * @return string
     */
    public function getSuccessColor(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'success_color',
            ScopeInterface::SCOPE_STORE
        ) ?: '#28a745';
    }

    /**
     * Get success message text color
     *
     * @return string
     */
    public function getSuccessTextColor(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'success_text_color',
            ScopeInterface::SCOPE_STORE
        ) ?: '#ffffff';
    }

    /**
     * Get error message text
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'error_message',
            ScopeInterface::SCOPE_STORE
        ) ?: 'There was an error adding the product to cart.';
    }

    /**
     * Get error message background color
     *
     * @return string
     */
    public function getErrorColor(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'error_color',
            ScopeInterface::SCOPE_STORE
        ) ?: '#ffc107';
    }

    /**
     * Get error message text color
     *
     * @return string
     */
    public function getErrorTextColor(): string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_MESSAGES . 'error_text_color',
            ScopeInterface::SCOPE_STORE
        ) ?: '#212529';
    }

    /**
     * Check if current theme is Hyvä Or ChildTheme
     *
     * @return bool
     */
    public function isHyvaOrChildTheme(): bool
    {
        $theme = $this->design->getDesignTheme();

        while ($theme) {
            $themeCode = $theme->getCode();
            
            if ($themeCode === 'Hyva/default') {
                return true;
            }

            $parentId = $theme->getParentId();
            if (!$parentId) {
                break;
            }
            $theme = $this->themeProvider->getThemeById($parentId);
        }

        return false;
    }
}
