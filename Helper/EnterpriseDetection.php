<?php

namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote;

class EnterpriseDetection extends AbstractHelper
{
    /**
     * @var ModuleManager
     */
    private $moduleManager;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param Context $context
     * @param ModuleManager $moduleManager
     * @param CustomerSession $customerSession
     */
    public function __construct(
        Context $context,
        ModuleManager $moduleManager,
        CustomerSession $customerSession
    ) {
        parent::__construct($context);
        $this->moduleManager = $moduleManager;
        $this->customerSession = $customerSession;
    }

    /**
     * Check if this is Magento Commerce Edition (formerly Enterprise Edition)
     *
     * @return bool
     */
    public function isEnterpriseEdition(): bool
    {
        // Modern Magento Commerce detection (2.4.x+)
        if ($this->isModernCommerceEdition()) {
            return true;
        }
        
        // Legacy Enterprise Edition detection (for older versions)
        if ($this->isLegacyEnterpriseEdition()) {
            return true;
        }
        
        return false;
    }

    /**
     * Check for modern Magento Commerce Edition (2.4.x+)
     *
     * @return bool
     */
    private function isModernCommerceEdition(): bool
    {
        // Check for Commerce-specific modules that are only available in Commerce Edition
        $commerceModules = [
            'Magento_CustomerSegment',      // Customer Segmentation
            'Magento_TargetRule',          // Related Products Rules
            'Magento_GiftCardAccount',     // Gift Cards
            'Magento_Reward',              // Reward Points
            'Magento_MultipleWishlist',    // Multiple Wishlists
            'Magento_Rma',                 // Returns (RMA)
            'Magento_AdvancedCheckout',    // Advanced Checkout
            'Magento_CatalogPermissions',  // Catalog Permissions
            'Magento_AdvancedSearch',      // Advanced Search
            'Magento_Banner',              // Banners
            'Magento_CatalogStaging',      // Content Staging
            'Magento_ScheduledImportExport' // Scheduled Import/Export
        ];

        foreach ($commerceModules as $module) {
            if ($this->moduleManager->isEnabled($module)) {
                return true;
            }
        }

        // Check for Commerce-specific classes
        $commerceClasses = [
            '\Magento\CustomerSegment\Model\Segment',
            '\Magento\TargetRule\Model\Rule',
            '\Magento\GiftCardAccount\Model\Giftcardaccount',
            '\Magento\Reward\Model\Reward',
            '\Magento\Rma\Model\Rma'
        ];

        foreach ($commerceClasses as $class) {
            if (class_exists($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for legacy Enterprise Edition (pre-2.4.x)
     *
     * @return bool
     */
    private function isLegacyEnterpriseEdition(): bool
    {
        // Legacy Enterprise module checks
        if ($this->moduleManager->isEnabled('Magento_Enterprise')) {
            return true;
        }

        // Legacy Enterprise class checks
        if (class_exists('\Magento\Enterprise\Model\ProductMetadata')) {
            return true;
        }

        return false;
    }

    /**
     * Check if B2B modules are enabled (modern Magento Commerce B2B)
     *
     * @return bool
     */
    public function isB2BEnabled(): bool
    {
        // Modern B2B modules (Magento Commerce 2.4.x+)
        $modernB2BModules = [
            'Magento_Company',              // Company Accounts
            'Magento_NegotiableQuote',      // Negotiable Quotes
            'Magento_SharedCatalog',        // Shared Catalogs
            'Magento_CompanyCredit',        // Company Credit
            'Magento_PurchaseOrder',        // Purchase Orders
            'Magento_CompanyPayment',       // Company Payment Methods
            'Magento_QuickOrder',           // Quick Order
            'Magento_RequisitionList',      // Requisition Lists
            'Magento_B2b'                   // Legacy B2B module
        ];

        foreach ($modernB2BModules as $module) {
            if ($this->moduleManager->isEnabled($module)) {
                return true;
            }
        }

        // Check for B2B-specific classes
        $b2bClasses = [
            '\Magento\Company\Model\Company',
            '\Magento\NegotiableQuote\Model\NegotiableQuote',
            '\Magento\SharedCatalog\Model\SharedCatalog',
            '\Magento\CompanyCredit\Model\Credit'
        ];

        foreach ($b2bClasses as $class) {
            if (class_exists($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current customer is in B2B context
     *
     * @return bool
     */
    public function isB2BCustomer(): bool
    {
        if (!$this->isB2BEnabled()) {
            return false;
        }

        try {
            $customer = $this->customerSession->getCustomer();
            if (!$customer || !$customer->getId()) {
                return false;
            }

            // Check if customer has company attributes (B2B customer)
            $extensionAttributes = $customer->getExtensionAttributes();
            if ($extensionAttributes && method_exists($extensionAttributes, 'getCompanyAttributes')) {
                $companyAttributes = $extensionAttributes->getCompanyAttributes();
                if ($companyAttributes && $companyAttributes->getCompanyId()) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // If any B2B check fails, assume not B2B to be safe
            $this->_logger->debug('B2B customer check failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Check if quote is a negotiable quote (B2B)
     *
     * @param Quote $quote
     * @return bool
     */
    public function isNegotiableQuote(Quote $quote): bool
    {
        if (!$this->isB2BEnabled()) {
            return false;
        }

        try {
            $extensionAttributes = $quote->getExtensionAttributes();
            if ($extensionAttributes && method_exists($extensionAttributes, 'getNegotiableQuote')) {
                $negotiableQuote = $extensionAttributes->getNegotiableQuote();
                return $negotiableQuote && $negotiableQuote->getQuoteId();
            }
        } catch (\Exception $e) {
            $this->_logger->debug('Negotiable quote check failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Check if we should skip loyalty processing (B2B context)
     *
     * @param Quote|null $quote
     * @return bool
     */
    public function shouldSkipLoyaltyProcessing(Quote $quote = null): bool
    {
        // Skip if B2B customer
        if ($this->isB2BCustomer()) {
            return true;
        }

        // Skip if negotiable quote
        if ($quote && $this->isNegotiableQuote($quote)) {
            return true;
        }

        return false;
    }

    /**
     * Log debug information about the current context
     *
     * @param string $context
     */
    public function logContext(string $context): void
    {
        if ($this->scopeConfig->isSetFlag('dev/debug/debug_logging')) {
            $this->_logger->debug("LoyaltyShop Context [{$context}]: " . json_encode([
                'is_enterprise' => $this->isEnterpriseEdition(),
                'is_b2b_enabled' => $this->isB2BEnabled(),
                'is_b2b_customer' => $this->isB2BCustomer(),
                'should_skip' => $this->shouldSkipLoyaltyProcessing()
            ]));
        }
    }
}
