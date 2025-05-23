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
            
            // Function to disable quantity inputs for free products
            function disableQtyForFreeProducts() {
                
                // Find all cart items
                $('.cart.item').each(function() {
                    var $item = $(this);
                    
                    // Different selectors for price elements in different themes
                    var priceSelectors = [
                        '.price-excluding-tax .price',  // Luma theme
                        '.price .price',                // Generic
                        '[data-price-type="finalPrice"] .price', // Hyva theme
                        '.price-final_price .price'     // Another common pattern
                    ];
                    
                    var price = 0;
                    var priceFound = false;
                    
                    // Try different selectors to find the price
                    for (var i = 0; i < priceSelectors.length; i++) {
                        var $priceElement = $item.find(priceSelectors[i]);
                        if ($priceElement.length) {
                            var priceText = $priceElement.text().trim();
                            // Remove currency symbols and formatting
                            price = parseFloat(priceText.replace(/[^0-9.-]+/g, ''));
                            priceFound = true;
                            break;
                        }
                    }
                    
                    // If price is 0 or we have the loyalty_locked_qty data attribute
                    if ((priceFound && price === 0) || $item.data('loyalty-locked-qty') === true) {
                        // Find quantity input with different selectors
                        var qtySelectors = [
                            '.input-text.qty',          // Luma theme
                            'input[name^="cart"][name$="[qty]"]', // Generic
                            '.input-qty'                // Hyva theme
                        ];
                        
                        for (var j = 0; j < qtySelectors.length; j++) {
                            var $qtyInput = $item.find(qtySelectors[j]);
                            if ($qtyInput.length) {
                                // Disable the input
                                $qtyInput.prop('disabled', true);
                                $qtyInput.css('pointer-events', 'none');
                                $qtyInput.css('background-color', '#f0f0f0');
                                $qtyInput.attr('readonly', 'readonly');
                                
                                // Also disable any +/- buttons
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease').css('pointer-events', 'none');
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease').css('opacity', '0.5');
                            }
                        }
                    }
                });
            }
            
            // Run on page load
            disableQtyForFreeProducts();
            
            // Also run when cart is updated via AJAX
            $(document).on('ajax:updateCartItemQty', disableQtyForFreeProducts);
            $(document).on('ajax:updateCart', disableQtyForFreeProducts);
            
            // For Hyva theme specific events
            $(document).on('cart:itemQtyUpdated', disableQtyForFreeProducts);
            $(document).on('cart:updated', disableQtyForFreeProducts);
        };
    });
}
