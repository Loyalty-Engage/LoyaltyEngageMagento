<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Magento\Store\Model\StoreManagerInterface;

class CheckoutCartItemRendererPlugin
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
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param EnterpriseDetection $enterpriseDetection
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        EnterpriseDetection $enterpriseDetection,
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger,
        StoreManagerInterface $storeManager
    ) {
        $this->enterpriseDetection = $enterpriseDetection;
        $this->logger = $logger;
        $this->loyaltyLogger = $loyaltyLogger;
        $this->storeManager = $storeManager;
    }

    /**
     * Modify the qty HTML for loyalty products
     *
     * @param CartItemRenderer $subject
     * @param string $result
     * @param QuoteItem $item
     * @return string
     */
    public function afterGetQtyHtml(CartItemRenderer $subject, $result, QuoteItem $item)
    {
        // Log environment context
        $this->logEnvironmentContext();

        // Skip processing for B2B contexts
        if ($this->enterpriseDetection->shouldSkipLoyaltyProcessing($item->getQuote())) {
            $this->logger->info('[LOYALTY-CART] B2B Context - Plugin skipped for item: ' . $item->getName());
            return $result;
        }

        // Enhanced loyalty product detection with detailed logging
        $isLocked = $this->isLoyaltyProductWithLogging($item);
        
        if ($isLocked) {
            $this->logger->info('[LOYALTY-CART] FINAL RESULT: LOYALTY PRODUCT - Quantity locked for: ' . $item->getName());
            
            // Add JavaScript to mark the cart item as loyalty-locked
            $script = '<script>
                require([\'jquery\', \'domReady!\'], function($) {
                    $(function() {
                        var itemId = \'' . $item->getId() . '\';
                        var isLocked = true;
                        $(\'input[name="cart[\' + itemId + \'][qty]"]\').closest(\'.cart.item\').attr(\'data-loyalty-locked-qty\', isLocked);
                    });
                });
            </script>';
            
            // Return a static qty display with a hidden input and the script
            return '<span>Qty: ' . (float)$item->getQty() . '</span>' .
                   '<input type="hidden" name="cart[' . $item->getId() . '][qty]" value="' . (float)$item->getQty() . '" />' .
                   $script;
        } else {
            $this->logger->info('[LOYALTY-CART] FINAL RESULT: REGULAR PRODUCT - Normal display for: ' . $item->getName());
        }
        
        return $result;
    }

    /**
     * Log environment context information
     */
    private function logEnvironmentContext(): void
    {
        static $logged = false;
        if (!$logged) {
            $this->logger->info('[LOYALTY-CART] Environment: Enterprise=' . 
                ($this->enterpriseDetection->isEnterpriseEdition() ? 'Yes' : 'No') . 
                ' | B2B Enabled=' . ($this->enterpriseDetection->isB2BEnabled() ? 'Yes' : 'No'));
            $logged = true;
        }
    }

    /**
     * Enhanced loyalty product detection with detailed logging
     *
     * @param QuoteItem $item
     * @return bool
     */
    private function isLoyaltyProductWithLogging(QuoteItem $item): bool
    {
        $productName = $item->getName();
        $productId = $item->getProductId();
        $price = $item->getPrice();
        $optionsCount = count($item->getOptions());

        // Log item analysis start
        $this->logger->info(sprintf(
            '[LOYALTY-CART] Item Analysis - Product: "%s" (ID: %s)',
            $productName,
            $productId
        ));
        
        $this->logger->info(sprintf(
            '[LOYALTY-CART] Price: $%.2f | Options Count: %d | Enterprise: %s | B2B: %s',
            $price,
            $optionsCount,
            $this->enterpriseDetection->isEnterpriseEdition() ? 'Yes' : 'No',
            $this->enterpriseDetection->isB2BEnabled() ? 'Yes' : 'No'
        ));

        // Method 1: Check for explicit loyalty_locked_qty option
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption) {
            $this->logger->info('[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): PASS - option value: ' . $loyaltyOption->getValue());
            return true;
        } else {
            $this->logger->info('[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): FAIL - no option found');
        }

        // Method 2: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info('[LOYALTY-CART] Detection Method 2 (item data): PASS - data value: ' . $loyaltyData);
            return true;
        } else {
            $this->logger->info('[LOYALTY-CART] Detection Method 2 (item data): FAIL - data value: ' . ($loyaltyData ?? 'null'));
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $item->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = @unserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (isset($option['label']) && $option['label'] === 'loyalty_locked_qty' && 
                        isset($option['value']) && $option['value'] === '1') {
                        $this->logger->info('[LOYALTY-CART] Detection Method 3 (additional_options): PASS - found loyalty flag in additional options');
                        return true;
                    }
                }
            }
            $this->logger->info('[LOYALTY-CART] Detection Method 3 (additional_options): FAIL - no loyalty flag found in additional options');
        } else {
            $this->logger->info('[LOYALTY-CART] Detection Method 3 (additional_options): FAIL - no additional options');
        }

        // Method 4: Enterprise-specific fallback
        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $item->getProduct();
            if ($product && $product->getData('loyalty_locked_qty')) {
                $this->logger->info('[LOYALTY-CART] Detection Method 4 (Enterprise fallback): PASS - product has loyalty flag');
                return true;
            } else {
                $this->logger->info('[LOYALTY-CART] Detection Method 4 (Enterprise fallback): FAIL - no product loyalty flag');
            }
        } else {
            $this->logger->info('[LOYALTY-CART] Detection Method 4 (Enterprise fallback): SKIPPED - not Enterprise edition');
        }

        // Log all item options for debugging
        $this->logAllItemOptions($item);

        return false;
    }

    /**
     * Log all item options for debugging purposes
     *
     * @param QuoteItem $item
     */
    private function logAllItemOptions(QuoteItem $item): void
    {
        $options = $item->getOptions();
        if (empty($options)) {
            $this->logger->info('[LOYALTY-CART] Item Options: None found');
            return;
        }

        $this->logger->info('[LOYALTY-CART] All Item Options:');
        foreach ($options as $option) {
            $this->logger->info(sprintf(
                '[LOYALTY-CART] - Option Code: "%s" | Value: "%s"',
                $option->getCode(),
                substr($option->getValue(), 0, 100) // Limit value length for readability
            ));
        }
    }
}
