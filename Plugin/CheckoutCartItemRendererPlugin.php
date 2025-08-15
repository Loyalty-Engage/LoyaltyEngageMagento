<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Magento\Store\Model\StoreManagerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CheckoutCartItemRendererPlugin
{
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
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     * @param StoreManagerInterface $storeManager
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger,
        StoreManagerInterface $storeManager,
        protected LoyaltyHelper $loyaltyHelper
    ) {
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

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            // Skip processing for Disable configuration.
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
            return '<span>Qty: ' . (float) $item->getQty() . '</span>' .
                '<input type="hidden" name="cart[' . $item->getId() . '][qty]" value="' . (float) $item->getQty() . '" />' .
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
            $this->logger->info('[LOYALTY-CART] Environment: Store=' . $this->storeManager->getStore()->getCode());
            $logged = true;
        }
    }

    /**
     * Enhanced loyalty product detection with strict validation and detailed logging
     *
     * @param QuoteItem $item
     * @return bool
     */
    private function isLoyaltyProductWithLogging(QuoteItem $item): bool
    {
        $productName = $item->getName();
        $productId = $item->getProductId();
        $sku = $item->getSku();
        $price = $item->getPrice();
        $customPrice = $item->getCustomPrice();
        $optionsCount = count($item->getOptions());

        // Log item analysis start
        $this->logger->info(sprintf(
            '[LOYALTY-CART] STRICT ANALYSIS - Product: "%s" (ID: %s, SKU: %s)',
            $productName,
            $productId,
            $sku
        ));

        $this->logger->info(sprintf(
            '[LOYALTY-CART] Price: $%.2f | Custom Price: %s | Options Count: %d',
            $price,
            $customPrice !== null ? '$' . number_format($customPrice, 2) : 'null',
            $optionsCount
        ));

        // STRICT METHOD 1: Check for explicit loyalty_locked_qty option with exact value match
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 1 (loyalty_locked_qty option): CONFIRMED LOYALTY - option value: ' . $loyaltyOption->getValue());

            // Additional validation: loyalty products should have custom price of 0
            if ($customPrice === 0.0 || $customPrice === '0.0000') {
                $this->logger->info('[LOYALTY-CART] STRICT Validation: PASS - Custom price is 0 as expected for loyalty product');
                return true;
            } else {
                $this->logger->warning(sprintf(
                    '[LOYALTY-CART] STRICT Validation: SUSPICIOUS - Loyalty option found but custom price is %s (expected 0)',
                    $customPrice ?? 'null'
                ));
                // Still return true but log the anomaly
                return true;
            }
        } else {
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 1 (loyalty_locked_qty option): FAIL - ' .
                ($loyaltyOption ? 'option value: ' . $loyaltyOption->getValue() : 'no option found'));
        }

        // STRICT METHOD 2: Check item data directly with exact value match
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 2 (item data): CONFIRMED LOYALTY - data value: ' . $loyaltyData);

            // Additional validation: loyalty products should have custom price of 0
            if ($customPrice === 0.0 || $customPrice === '0.0000') {
                $this->logger->info('[LOYALTY-CART] STRICT Validation: PASS - Custom price is 0 as expected for loyalty product');
                return true;
            } else {
                $this->logger->warning(sprintf(
                    '[LOYALTY-CART] STRICT Validation: SUSPICIOUS - Loyalty data found but custom price is %s (expected 0)',
                    $customPrice ?? 'null'
                ));
                // Still return true but log the anomaly
                return true;
            }
        } else {
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 2 (item data): FAIL - data value: ' . ($loyaltyData ?? 'null'));
        }

        // STRICT METHOD 3: Check additional_options for loyalty flag with exact value match
        $additionalOptions = $item->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = @unserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (
                        isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                        isset($option['value']) && $option['value'] === '1'
                    ) {
                        $this->logger->info('[LOYALTY-CART] STRICT Detection Method 3 (additional_options): CONFIRMED LOYALTY - found loyalty flag in additional options');

                        // Additional validation: loyalty products should have custom price of 0
                        if ($customPrice === 0.0 || $customPrice === '0.0000') {
                            $this->logger->info('[LOYALTY-CART] STRICT Validation: PASS - Custom price is 0 as expected for loyalty product');
                            return true;
                        } else {
                            $this->logger->warning(sprintf(
                                '[LOYALTY-CART] STRICT Validation: SUSPICIOUS - Loyalty additional option found but custom price is %s (expected 0)',
                                $customPrice ?? 'null'
                            ));
                            // Still return true but log the anomaly
                            return true;
                        }
                    }
                }
            }
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 3 (additional_options): FAIL - no loyalty flag found in additional options');
        } else {
            $this->logger->info('[LOYALTY-CART] STRICT Detection Method 3 (additional_options): FAIL - no additional options');
        }

        // STRICT METHOD 4: Enterprise-specific fallback (DISABLED for strict mode)
        // This method is disabled in strict mode to prevent false positives
        $this->logger->info('[LOYALTY-CART] STRICT Detection Method 4 (Enterprise fallback): DISABLED - strict mode prevents false positives');

        // Log all item options for debugging
        $this->logAllItemOptions($item);

        // FINAL DETERMINATION: If none of the strict methods matched, it's a regular product
        $this->logger->info(sprintf(
            '[LOYALTY-CART] STRICT FINAL DETERMINATION: REGULAR PRODUCT - "%s" (SKU: %s) - No loyalty markers found',
            $productName,
            $sku
        ));

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
