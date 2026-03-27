<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Logger;
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
     * @var Session
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Logger $loyaltyLogger
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        Logger $loyaltyLogger,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        protected LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    /**
     * Execute observer
     * Note: Logging is now minimal and uses masked emails for privacy
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        // Only log if logging is enabled
        if (!$this->loyaltyLogger->isLoggingEnabled()) {
            return;
        }

        try {
            $quoteItem = $observer->getEvent()->getQuoteItem();
            $product = $observer->getEvent()->getProduct();

            if (!$quoteItem || !$product) {
                return;
            }

            // Check if this is a loyalty product - only log loyalty products
            $isLoyaltyProduct = $this->isLoyaltyProduct($quoteItem);
            
            // Only log loyalty product additions (reduces noise significantly)
            if ($isLoyaltyProduct) {
                $customerEmail = $this->getMaskedCustomerEmail();
                $source = $this->determineAdditionSource($quoteItem);

                $this->loyaltyLogger->logCartAddition(
                    Logger::ACTION_LOYALTY,
                    $product->getSku(),
                    $customerEmail,
                    $source,
                    [
                        'product_name' => $product->getName(),
                        'qty' => $quoteItem->getQty()
                    ]
                );
            }

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                Logger::COMPONENT_OBSERVER,
                Logger::ACTION_ERROR,
                'Exception in CartProductAddObserver: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get masked customer email for privacy
     *
     * @return string
     */
    private function getMaskedCustomerEmail(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->loyaltyLogger->maskEmail(
                $this->customerSession->getCustomer()->getEmail()
            );
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
            $value = $this->safeUnserialize($additionalOptions->getValue());
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

        // Method 4: Check product data (universal fallback)
        $product = $quoteItem->getProduct();
        if ($product && $product->getData('loyalty_locked_qty')) {
            return true;
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

        return 'regular-cart';
    }

    /**
     * Safely unserialize data with JSON fallback
     * Prevents PHP object injection vulnerabilities
     *
     * @param string|null $data
     * @return array|null
     */
    private function safeUnserialize(?string $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        // First try JSON decode (preferred, safer)
        $jsonResult = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonResult)) {
            return $jsonResult;
        }

        // Fallback to unserialize with allowed_classes = false for security
        try {
            $result = @unserialize($data, ['allowed_classes' => false]);
            return is_array($result) ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
