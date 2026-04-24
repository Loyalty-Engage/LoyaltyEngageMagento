<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
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

        $isLocked = $this->loyaltyHelper->isLoyaltyProduct($item, true);

        if ($isLocked) {
            $this->loyaltyHelper->log(
                "debug",
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_LOYALTY,
                'Loyalty product qty locked: %s (SKU: %s)', $item->getName(), $item->getSku()
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
        } else {
            $this->loyaltyHelper->log(
                "debug",
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_LOYALTY,
                'FINAL RESULT: REGULAR PRODUCT - Normal display for: ' . $item->getName()
            );
        }

        return $result;
    }
}
