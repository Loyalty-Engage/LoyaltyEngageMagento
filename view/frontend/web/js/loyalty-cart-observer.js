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
                // Find all cart items that haven't been processed yet
                $('.cart.item:not(.loyalty-qty-locked)').each(function() {
                    var $item = $(this);
                    
                    // Different selectors for price elements
                    var priceSelectors = [
                        '.price-excluding-tax .price',  // Luma theme
                        '.price .price',                // Generic
                        '.price-final_price .price',    // Another common pattern
                        '.price-container .price'       // Another variation
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
                    
                    // Check for data attribute that might have been set by PHP
                    var dataLocked = $item.attr('data-loyalty-locked-qty');
                    var isLocked = (dataLocked === 'true' || dataLocked === '1');
                    
                    // If price is 0 or we have the loyalty_locked_qty data attribute
                    if ((priceFound && price === 0) || isLocked) {
                        
                        // Find quantity input with different selectors
                        var qtySelectors = [
                            '.input-text.qty',          // Luma theme
                            'input[name^="cart"][name$="[qty]"]'  // Generic
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
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease, .qty-modifier').css('pointer-events', 'none');
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease, .qty-modifier').css('opacity', '0.5');
                                
                                // Add a class to mark this item as processed
                                $item.addClass('loyalty-qty-locked');
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
            
            // Set up a MutationObserver to watch for DOM changes
            if (typeof MutationObserver !== 'undefined') {
                // Target the cart container
                var cartContainers = [
                    '.cart.main',           // Luma
                    '.cart-container',      // Generic
                    '#shopping-cart-table', // Common table ID
                    '.checkout-cart-index'  // Page container
                ];
                
                var targetNode = null;
                
                // Find the first available container
                for (var i = 0; i < cartContainers.length; i++) {
                    var container = document.querySelector(cartContainers[i]);
                    if (container) {
                        targetNode = container;
                        break;
                    }
                }
                
                // If we found a container, observe it
                if (targetNode) {
                    
                    // Options for the observer (which mutations to observe)
                    var config = { attributes: false, childList: true, subtree: true };
                    
                    // Create an observer instance linked to the callback function
                    var observer = new MutationObserver(function(mutationsList, observer) {
                        // Use a debounce mechanism to avoid excessive processing
                        clearTimeout(window.loyaltyQtyObserverTimeout);
                        window.loyaltyQtyObserverTimeout = setTimeout(function() {
                            disableQtyForFreeProducts();
                        }, 200);
                    });
                    
                    // Start observing the target node for configured mutations
                    observer.observe(targetNode, config);
                }
            }
            
            // Remove the setInterval to improve performance
        };
    });
}
