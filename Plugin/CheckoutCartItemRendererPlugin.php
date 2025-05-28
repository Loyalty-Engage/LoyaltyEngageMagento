<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;

class CheckoutCartItemRendererPlugin
{
    /**
     * Modify the qty HTML for free products
     *
     * @param CartItemRenderer $subject
     * @param string $result
     * @param QuoteItem $item
     * @return string
     */
    public function afterGetQtyHtml(CartItemRenderer $subject, $result, QuoteItem $item)
    {
        // Check if this is a free product (price is 0)
        if ((float)$item->getPrice() == 0 || $item->getOptionByCode('loyalty_locked_qty')) {
            // Return a static qty display with a hidden input
            return '<span>Qty: ' . (float)$item->getQty() . '</span>' .
                   '<input type="hidden" name="cart[' . $item->getId() . '][qty]" value="' . (float)$item->getQty() . '" />';
        }
        
        return $result;
    }
}
