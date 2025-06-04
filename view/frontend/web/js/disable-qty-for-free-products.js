// Immediately check if we're on the cart page before defining the module
if (window.location.href.indexOf('checkout/cart') === -1) {
    // Not on cart page, define an empty module
    define(['jquery'], function ($) {
        'use strict';
        return function() {};
    });
} else {
    // On cart page, define the full module
    define([
        'jquery',
        'domReady!'
    ], function ($) {
        'use strict';

        return function (config) {
            
            // Find all cart items
            $('.cart.item').each(function() {
                var $item = $(this);
                var price = parseFloat($item.find('.price-excluding-tax .price').text().replace(/[^0-9.-]+/g, ''));
                
                // If price is 0, disable quantity input
                if (price === 0) {
                    var $qtyInput = $item.find('.input-text.qty');
                    var qty = $qtyInput.val();
                    
                    // Replace with static text and hidden input
                    if ($qtyInput.length) {
                        $qtyInput.prop('disabled', true);
                        $qtyInput.css('pointer-events', 'none');
                        $qtyInput.css('background-color', '#f0f0f0');
                        $qtyInput.attr('readonly', 'readonly');
                    }
                }
            });
        };
    });
}
