<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Block\Cart\Item\Renderer as CartItemRenderer;
use Magento\Quote\Model\Quote\Item as QuoteItem;

class CheckoutCartItemRendererPlugin
{
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
        $isLocked = $item->getOptionByCode('loyalty_locked_qty') || (float)$item->getPrice() == 0;
        
        if ($isLocked) {
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
        }
        
        return $result;
    }
}
