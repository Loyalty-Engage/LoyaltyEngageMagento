<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Item as QuoteItem;
use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class QuoteItemPriceProtectionPlugin
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
     * Monitor and log custom price changes (less aggressive approach)
     *
     * @param QuoteItem $subject
     * @param callable $proceed
     * @param float $price
     * @return QuoteItem
     */
    public function aroundSetCustomPrice(QuoteItem $subject, callable $proceed, $price)
    {
        $isLoyalty = $this->isConfirmedLoyaltyProduct($subject);
        $currentPrice = $subject->getCustomPrice();
        
        // Log the price change attempt
        $this->loyaltyLogger->info(
            'PRICE-PROTECTION',
            $isLoyalty ? 'LOYALTY-PRICE-SET' : 'REGULAR-PRICE-SET',
            sprintf('Custom price change: %s (SKU: %s) - %.2f → %.2f', 
                $subject->getName(), $subject->getSku(), $currentPrice, $price),
            [
                'sku' => $subject->getSku(),
                'product_name' => $subject->getName(),
                'is_loyalty' => $isLoyalty,
                'current_custom_price' => $currentPrice,
                'new_custom_price' => $price,
                'price_difference' => $price - $currentPrice,
                'stack_trace' => $this->getStackTraceSummary()
            ]
        );

        // Allow the change to proceed for both loyalty and regular products
        // The QuoteRepositorySavePlugin will handle protection during save
        return $proceed($price);
    }

    /**
     * Monitor and log original custom price changes (less aggressive approach)
     *
     * @param QuoteItem $subject
     * @param callable $proceed
     * @param float $price
     * @return QuoteItem
     */
    public function aroundSetOriginalCustomPrice(QuoteItem $subject, callable $proceed, $price)
    {
        $isLoyalty = $this->isConfirmedLoyaltyProduct($subject);
        $currentPrice = $subject->getOriginalCustomPrice();
        
        // Log the original price change attempt
        $this->loyaltyLogger->info(
            'PRICE-PROTECTION',
            $isLoyalty ? 'LOYALTY-ORIGINAL-PRICE-SET' : 'REGULAR-ORIGINAL-PRICE-SET',
            sprintf('Original custom price change: %s (SKU: %s) - %.2f → %.2f', 
                $subject->getName(), $subject->getSku(), $currentPrice, $price),
            [
                'sku' => $subject->getSku(),
                'product_name' => $subject->getName(),
                'is_loyalty' => $isLoyalty,
                'current_original_custom_price' => $currentPrice,
                'new_original_custom_price' => $price,
                'price_difference' => $price - $currentPrice,
                'stack_trace' => $this->getStackTraceSummary()
            ]
        );

        // Allow the change to proceed for both loyalty and regular products
        // The QuoteRepositorySavePlugin will handle protection during save
        return $proceed($price);
    }

    /**
     * Strict loyalty product detection - only confirmed loyalty products
     *
     * @param QuoteItem $subject
     * @return bool
     */
    private function isConfirmedLoyaltyProduct(QuoteItem $subject): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $subject->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Confirmed loyalty product via option: %s',
                $subject->getName()
            ));
            return true;
        }

        // Method 2: Check item data directly (secondary check)
        $loyaltyData = $subject->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Confirmed loyalty product via data: %s',
                $subject->getName()
            ));
            return true;
        }

        // All other cases are treated as regular products
        $this->logger->info(sprintf(
            '[LOYALTY-PRICE-PROTECTION] Confirmed regular product: %s (SKU: %s)',
            $subject->getName(),
            $subject->getSku()
        ));
        
        return false;
    }

    /**
     * Get simplified stack trace summary
     *
     * @return string
     */
    private function getStackTraceSummary(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        foreach ($trace as $step) {
            if (isset($step['class']) && isset($step['function'])) {
                // Skip our own plugin calls
                if (strpos($step['class'], 'QuoteItemPriceProtectionPlugin') !== false) {
                    continue;
                }
                
                return $step['class'] . '::' . $step['function'];
            }
        }
        
        return 'Unknown';
    }
}
