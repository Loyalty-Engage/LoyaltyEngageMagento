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
     * Strict loyalty product detection with detailed logging - matches CheckoutCartItemRendererPlugin
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProductWithLogging($item): bool
    {
        $productName = $item->getName();
        $productId = $item->getProductId();
        $sku = $item->getSku();
        $customPrice = $item->getCustomPrice();

        $this->logger->info(sprintf(
            '[LOYALTY-CART] ViewModel STRICT Analysis - Product: "%s" (ID: %s, SKU: %s)',
            $productName,
            $productId,
            $sku
        ));

        $this->logger->info(sprintf(
            '[LOYALTY-CART] ViewModel Custom Price: %s',
            $customPrice !== null ? '$' . number_format($customPrice, 2) : 'null'
        ));

        // STRICT METHOD 1: Check for explicit loyalty_locked_qty option with exact value match
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 1 (loyalty_locked_qty option): CONFIRMED LOYALTY - option value: ' . $loyaltyOption->getValue());
            
            // Additional validation: loyalty products should have custom price of 0
            if ($customPrice === 0.0 || $customPrice === '0.0000') {
                $this->logger->info('[LOYALTY-CART] ViewModel STRICT Validation: PASS - Custom price is 0 as expected');
                return true;
            } else {
                $this->logger->warning(sprintf(
                    '[LOYALTY-CART] ViewModel STRICT Validation: SUSPICIOUS - Loyalty option found but custom price is %s (expected 0)',
                    $customPrice ?? 'null'
                ));
                // Still return true but log the anomaly
                return true;
            }
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 1 (loyalty_locked_qty option): FAIL - ' . 
                ($loyaltyOption ? 'option value: ' . $loyaltyOption->getValue() : 'no option found'));
        }

        // STRICT METHOD 2: Check item data directly with exact value match
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 2 (item data): CONFIRMED LOYALTY - data value: ' . $loyaltyData);
            
            // Additional validation: loyalty products should have custom price of 0
            if ($customPrice === 0.0 || $customPrice === '0.0000') {
                $this->logger->info('[LOYALTY-CART] ViewModel STRICT Validation: PASS - Custom price is 0 as expected');
                return true;
            } else {
                $this->logger->warning(sprintf(
                    '[LOYALTY-CART] ViewModel STRICT Validation: SUSPICIOUS - Loyalty data found but custom price is %s (expected 0)',
                    $customPrice ?? 'null'
                ));
                // Still return true but log the anomaly
                return true;
            }
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 2 (item data): FAIL - data value: ' . ($loyaltyData ?? 'null'));
        }

        // STRICT METHOD 3: Check additional_options for loyalty flag with exact value match
        $additionalOptions = $item->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = @unserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (isset($option['label']) && $option['label'] === 'loyalty_locked_qty' && 
                        isset($option['value']) && $option['value'] === '1') {
                        $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 3 (additional_options): CONFIRMED LOYALTY - found loyalty flag');
                        
                        // Additional validation: loyalty products should have custom price of 0
                        if ($customPrice === 0.0 || $customPrice === '0.0000') {
                            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Validation: PASS - Custom price is 0 as expected');
                            return true;
                        } else {
                            $this->logger->warning(sprintf(
                                '[LOYALTY-CART] ViewModel STRICT Validation: SUSPICIOUS - Loyalty additional option found but custom price is %s (expected 0)',
                                $customPrice ?? 'null'
                            ));
                            // Still return true but log the anomaly
                            return true;
                        }
                    }
                }
            }
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 3 (additional_options): FAIL - no loyalty flag found');
        } else {
            $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 3 (additional_options): FAIL - no additional options');
        }

        // STRICT METHOD 4: Enterprise-specific fallback (DISABLED for strict mode)
        // This method is disabled in strict mode to prevent false positives
        $this->logger->info('[LOYALTY-CART] ViewModel STRICT Method 4 (Enterprise fallback): DISABLED - strict mode prevents false positives');

        // FINAL DETERMINATION: If none of the strict methods matched, it's a regular product
        $this->logger->info(sprintf(
            '[LOYALTY-CART] ViewModel STRICT FINAL DETERMINATION: REGULAR PRODUCT - "%s" (SKU: %s) - No loyalty markers found',
            $productName,
            $sku
        ));

        return false;
    }
}
