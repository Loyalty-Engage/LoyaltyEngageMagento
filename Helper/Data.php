<?php
namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_EXPORT = 'loyalty/export/';
    const XML_PATH_GENERAL = 'loyalty/general/';
    
    /**
     * @var DesignInterface
     */
    private $design;
    
    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param DesignInterface $design
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        DesignInterface $design
    ) {
        $this->design = $design;
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
     * Check if the current theme is Hyvä
     *
     * @return bool
     */
    public function isHyvaTheme(): bool
    {
        $themeId = $this->design->getDesignTheme()->getThemeId();
        $themePath = $this->design->getDesignTheme()->getThemePath();
        
        // Check if theme path or code contains 'hyva'
        if (stripos($themePath, 'hyva') !== false) {
            return true;
        }
        
        // Get theme code and check if it contains 'hyva'
        $themeCode = $this->design->getDesignTheme()->getCode();
        if (stripos($themeCode, 'hyva') !== false) {
            return true;
        }
        
        return false;
    }
}
