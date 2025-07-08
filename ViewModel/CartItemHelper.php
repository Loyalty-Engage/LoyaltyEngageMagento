<?php

namespace LoyaltyEngage\LoyaltyShop\ViewModel;

use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class CartItemHelper implements \Magento\Framework\View\Element\Block\ArgumentInterface
{
    /**
     * @var EnterpriseDetection
     */
    private $enterpriseDetection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @param EnterpriseDetection $enterpriseDetection
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        EnterpriseDetection $enterpriseDetection,
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->enterpriseDetection = $enterpriseDetection;
        $this->logger = $logger;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Check if the item quantity should be locked
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    public function isQtyLocked($item): bool
    {
        // Skip processing for B2B contexts
        if ($this->enterpriseDetection->shouldSkipLoyaltyProcessing($item->getQuote())) {
            $this->logger->info('[LOYALTY-CART] ViewModel - B2B Context - Skipped for item: ' . $item->getName());
            return false;
        }

        $this->logger->info('[LOYALTY-CART] ViewModel - Processing item: ' . $item->getName());

        return $this->isLoyaltyProductWithLogging($item);
    }

    /**
     * Enhanced loyalty product detection with detailed logging
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProductWithLogging($item): bool
    {
        $productName = $item->getName();
        $productId = $item->getProductId();

        $this->logger->info(sprintf(
            '[LOYALTY-CART] ViewModel Analysis - Product: "%s" (ID: %s)',
            $productName,
            $productId
        ));

        // Method 1: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 1 (item data): PASS - data value: ' . $loyaltyData);
            return true;
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 1 (item data): FAIL - data value: ' . ($loyaltyData ?? 'null'));
        }

        // Method 2: Check for explicit loyalty_locked_qty option
        $loyaltyLockedQty = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyLockedQty) {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 2 (loyalty_locked_qty option): PASS - option value: ' . $loyaltyLockedQty->getValue());
            return true;
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 2 (loyalty_locked_qty option): FAIL - no option found');
        }

        // Method 3: Check additional_options for loyalty flag
        $options = $item->getOptionByCode('additional_options');
        if ($options) {
            $value = @unserialize($options->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (isset($option['label']) && $option['label'] === 'loyalty_locked_qty' && 
                        isset($option['value']) && $option['value'] === '1') {
                        $this->logger->info('[LOYALTY-CART] ViewModel Method 3 (additional_options): PASS - found loyalty flag');
                        return true;
                    }
                }
            }
            $this->logger->info('[LOYALTY-CART] ViewModel Method 3 (additional_options): FAIL - no loyalty flag found');
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 3 (additional_options): FAIL - no additional options');
        }

        // Method 4: Enterprise-specific fallback
        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $item->getProduct();
            if ($product && $product->getData('loyalty_locked_qty')) {
                $this->logger->info('[LOYALTY-CART] ViewModel Method 4 (Enterprise fallback): PASS - product has loyalty flag');
                return true;
            } else {
                $this->logger->info('[LOYALTY-CART] ViewModel Method 4 (Enterprise fallback): FAIL - no product loyalty flag');
            }
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel Method 4 (Enterprise fallback): SKIPPED - not Enterprise edition');
        }

        $this->logger->info('[LOYALTY-CART] ViewModel FINAL RESULT: REGULAR PRODUCT for: ' . $productName);
        return false;
    }
}
