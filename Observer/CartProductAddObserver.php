<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Logger;
use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
use Magento\Customer\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartProductAddObserver implements ObserverInterface
{
    /**
     * @var Logger
     */
    private $loyaltyLogger;

    /**
     * @var EnterpriseDetection
     */
    private $enterpriseDetection;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Logger $loyaltyLogger
     * @param EnterpriseDetection $enterpriseDetection
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        Logger $loyaltyLogger,
        EnterpriseDetection $enterpriseDetection,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        protected LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->enterpriseDetection = $enterpriseDetection;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            try {
                $quoteItem = $observer->getEvent()->getQuoteItem();
                $product = $observer->getEvent()->getProduct();

                if (!$quoteItem || !$product) {
                    return;
                }

                // Log environment context (once per session)
                $this->logEnvironmentContext();

                // Determine customer email
                $customerEmail = $this->getCustomerEmail();

                // Check if this is a loyalty product
                $isLoyaltyProduct = $this->isLoyaltyProduct($quoteItem);
                $productType = $isLoyaltyProduct ? Logger::ACTION_LOYALTY : Logger::ACTION_REGULAR;

                // Determine source of addition
                $source = $this->determineAdditionSource($quoteItem);

                // Prepare additional data
                $additionalData = [
                    'product_id' => $product->getId(),
                    'product_name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'qty' => $quoteItem->getQty(),
                    'quote_id' => $quoteItem->getQuoteId(),
                    'item_id' => $quoteItem->getId(),
                    'store_id' => $this->storeManager->getStore()->getId(),
                    'is_loyalty_detected' => $isLoyaltyProduct,
                    'detection_methods' => $this->getDetectionMethods($quoteItem)
                ];

                // Log the cart addition
                $this->loyaltyLogger->logCartAddition(
                    $productType,
                    $product->getSku(),
                    $customerEmail,
                    $source,
                    $additionalData
                );

                // If it's a loyalty product, log additional details
                if ($isLoyaltyProduct) {
                    $this->logLoyaltyProductDetails($quoteItem, $product);
                }

            } catch (\Exception $e) {
                $this->loyaltyLogger->error(
                    Logger::COMPONENT_OBSERVER,
                    Logger::ACTION_ERROR,
                    'Exception in CartProductAddObserver: ' . $e->getMessage(),
                    [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
        }
    }

    /**
     * Log environment context
     */
    private function logEnvironmentContext(): void
    {
        static $logged = false;
        if (!$logged) {
            $environmentData = [
                'is_enterprise' => $this->enterpriseDetection->isEnterpriseEdition(),
                'is_b2b' => $this->enterpriseDetection->isB2BEnabled(),
                'store_code' => $this->storeManager->getStore()->getCode(),
                'store_id' => $this->storeManager->getStore()->getId(),
                'website_id' => $this->storeManager->getStore()->getWebsiteId()
            ];

            $this->loyaltyLogger->logEnvironmentContext($environmentData);
            $logged = true;
        }
    }

    /**
     * Get customer email
     *
     * @return string
     */
    private function getCustomerEmail(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->customerSession->getCustomer()->getEmail();
        }
        return 'guest';
    }

    /**
     * Check if quote item is a loyalty product
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return bool
     */
    private function isLoyaltyProduct($quoteItem): bool
    {
        // Method 1: Check for loyalty_locked_qty option
        $loyaltyOption = $quoteItem->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() == '1') {
            return true;
        }

        // Method 2: Check item data directly
        $loyaltyData = $quoteItem->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options
        $additionalOptions = $quoteItem->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = @unserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (
                        isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                        isset($option['value']) && $option['value'] === '1'
                    ) {
                        return true;
                    }
                }
            }
        }

        // Method 4: Check product data (Enterprise fallback)
        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $quoteItem->getProduct();
            if ($product && $product->getData('loyalty_locked_qty')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the source of product addition
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return string
     */
    private function determineAdditionSource($quoteItem): string
    {
        // Check if added via loyalty API
        if ($this->isLoyaltyProduct($quoteItem)) {
            return 'loyalty-api';
        }

        // Check for specific request parameters or headers that might indicate source
        $request = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\App\RequestInterface::class);

        if ($request->getParam('loyalty_add')) {
            return 'loyalty-frontend';
        }

        return 'regular-cart';
    }

    /**
     * Get detection methods used for loyalty identification
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return array
     */
    private function getDetectionMethods($quoteItem): array
    {
        $methods = [];

        // Check each detection method
        $loyaltyOption = $quoteItem->getOptionByCode('loyalty_locked_qty');
        $methods['option_check'] = $loyaltyOption ? $loyaltyOption->getValue() : 'not_found';

        $loyaltyData = $quoteItem->getData('loyalty_locked_qty');
        $methods['data_check'] = $loyaltyData ?? 'not_found';

        $additionalOptions = $quoteItem->getOptionByCode('additional_options');
        $methods['additional_options'] = $additionalOptions ? 'found' : 'not_found';

        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $quoteItem->getProduct();
            $methods['enterprise_fallback'] = ($product && $product->getData('loyalty_locked_qty')) ? 'found' : 'not_found';
        }

        return $methods;
    }

    /**
     * Log additional details for loyalty products
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @param \Magento\Catalog\Model\Product $product
     */
    private function logLoyaltyProductDetails($quoteItem, $product): void
    {
        $details = [
            'custom_price' => $quoteItem->getCustomPrice(),
            'original_custom_price' => $quoteItem->getOriginalCustomPrice(),
            'base_price' => $quoteItem->getBasePrice(),
            'price' => $quoteItem->getPrice(),
            'row_total' => $quoteItem->getRowTotal(),
            'options_count' => count($quoteItem->getOptions())
        ];

        $this->loyaltyLogger->info(
            Logger::COMPONENT_OBSERVER,
            Logger::ACTION_LOYALTY,
            sprintf('Loyalty product details for %s: %s', $product->getSku(), json_encode($details)),
            $details
        );
    }
}
