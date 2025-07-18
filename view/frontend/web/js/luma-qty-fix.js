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
            // Function to immediately disable quantity inputs for loyalty products
            function disableQtyInputsForLoyaltyProducts() {
                
                // Find all cart items that are explicitly marked as loyalty products
                $('.cart.item').each(function() {
                    var $item = $(this);
                    
                    // Check for data attribute that might have been set by PHP
                    var dataLocked = $item.attr('data-loyalty-locked-qty');
                    var isLocked = (dataLocked === 'true' || dataLocked === '1');
                    
                    // Only process items that are explicitly marked as loyalty products
                    if (isLocked) {
                        var $qtyInput = $item.find('.input-text.qty');
                        
                        // Make sure we found the input
                        if ($qtyInput.length) {
                            try {
                                // Try to get the item ID from the name attribute
                                var nameAttr = $qtyInput.attr('name');
                                if (nameAttr) {
                                    var matches = nameAttr.match(/cart\[(\d+)\]/);
                                    if (matches && matches.length > 1) {
                                        var itemId = matches[1];
                                        var qty = $qtyInput.val();
                                        
                                        // Replace the input with a static display and hidden input
                                        var $qtyContainer = $qtyInput.closest('.control.qty');
                                        
                                        // Create a hidden input to maintain form submission
                                        var $hiddenInput = $('<input>')
                                            .attr('type', 'hidden')
                                            .attr('name', 'cart[' + itemId + '][qty]')
                                            .attr('value', qty);
                                        
                                        // Create a static display
                                        var $staticQty = $('<span>')
                                            .addClass('qty-static')
                                            .text('Qty: ' + qty)
                                            .css({
                                                'font-weight': 'bold',
                                                'display': 'block',
                                                'margin-top': '7px'
                                            });
                                        
                                        // Replace the input with our static display and hidden input
                                        $qtyInput.replaceWith($hiddenInput);
                                        $qtyContainer.prepend($staticQty);
                                        
                                        // Add a class to the item for styling
                                        $item.addClass('loyalty-qty-locked');
                                        return; // Successfully processed this item
                                    }
                                }
                            } catch (e) {
                            }
                            
                            // Fallback: If we couldn't extract the item ID or any other error occurred,
                            // just disable the input directly
                            $qtyInput.prop('disabled', true)
                                .css('background-color', '#f0f0f0')
                                .css('pointer-events', 'none')
                                .attr('readonly', 'readonly');
                            
                            $item.addClass('loyalty-qty-locked');
                        }
                    }
                });
            }
            
            // Run immediately
            disableQtyInputsForLoyaltyProducts();
            
            // Also run after any AJAX updates
            $(document).on('ajaxComplete', function(event, xhr, settings) {
                if (settings.url.indexOf('/checkout/cart/') !== -1) {
                    setTimeout(disableQtyInputsForLoyaltyProducts, 500);
                }
            });
            
            // No need for setInterval, the ajaxComplete event should catch all updates
        };
    });
}
