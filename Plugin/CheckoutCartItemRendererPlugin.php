<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CheckoutCartItemRendererPlugin
{
    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @param LoyaltyLogger $loyaltyLogger
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        LoyaltyLogger $loyaltyLogger,
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Modify the qty HTML for loyalty products to lock the quantity
     *
     * @param CartItemRenderer $subject
     * @param string $result
     * @param QuoteItem $item
     * @return string
     */
    public function afterGetQtyHtml(CartItemRenderer $subject, $result, QuoteItem $item): string
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $result;
        }

        $isLocked = $this->isLoyaltyProduct($item);

        if ($isLocked) {
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf('Loyalty product qty locked: %s (SKU: %s)', $item->getName(), $item->getSku())
            );

            // Add JavaScript to mark the cart item as loyalty-locked
            $script = '<script>
                require([\'jquery\', \'domReady!\'], function($) {
                    $(function() {
                        var itemId = \'' . $item->getId() . '\';
                        $(\'input[name="cart[\' + itemId + \'][qty]"]\').closest(\'.cart.item\').attr(\'data-loyalty-locked-qty\', true);
                    });
                });
            </script>';

            return '<span>Qty: ' . (float) $item->getQty() . '</span>' .
                '<input type="hidden" name="cart[' . $item->getId() . '][qty]" value="' . (float) $item->getQty() . '" />' .
                $script;
        }

        return $result;
    }

    /**
     * Check if a quote item is a loyalty product
     *
     * @param QuoteItem $item
     * @return bool
     */
    private function isLoyaltyProduct(QuoteItem $item): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }

        // Method 2: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $item->getOptionByCode('additional_options');
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

        return false;
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

        $jsonResult = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonResult)) {
            return $jsonResult;
        }

        try {
            $result = @unserialize($data, ['allowed_classes' => false]);
            return is_array($result) ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
