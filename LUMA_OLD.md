# LoyaltyEngage LoyaltyShop - Frontend JavaScript

This document contains the standalone JavaScript code for the LoyaltyEngage LoyaltyShop functionality that can be implemented via Magento PageBuilder or custom HTML blocks.

## Overview

The frontend JavaScript provides three main functionalities:
1. **Loyalty Cart Management** - Add products to cart and claim discount codes
2. **Cart Observer** - Disable quantity inputs for loyalty/free products on cart page
3. **Quantity Fix** - Enhanced quantity control with static displays

## Installation via PageBuilder

1. Create a new HTML block in PageBuilder
2. Copy the complete JavaScript code below
3. Paste it into the HTML block
4. Ensure the CSS styles are also included (see CSS section)
5. Save and publish

## Complete Standalone JavaScript Code

```html
<script>
(function() {
    'use strict';
    
    // Wait for DOM and jQuery to be ready
    function initLoyaltyShop() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initLoyaltyShop, 100);
            return;
        }
        
        var $ = jQuery;
        
        // Global variables
        window.loyaltyShopCustomerId = null;
        
        // ===== LOYALTY CART FUNCTIONALITY =====
        
        function showLoyaltyMessageBar(message, isSuccess) {
            isSuccess = isSuccess !== false; // Default to true
            var $bar = $('#loyalty-message-bar');
        
            if ($bar.length === 0) {
                $bar = $('<div id="loyalty-message-bar"></div>').css({
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    width: '100%',
                    padding: '12px 20px',
                    'text-align': 'center',
                    'font-weight': 'bold',
                    'font-size': '16px',
                    'z-index': 10000,
                    display: 'none'
                });
                $('body').prepend($bar);
            }
        
            $bar.stop(true, true).css({
                background: isSuccess ? '#28a745' : '#ffc107',
                color: isSuccess ? '#fff' : '#212529',
                borderBottom: '2px solid ' + (isSuccess ? '#218838' : '#e0a800')
            }).text(message).fadeIn();
        
            clearTimeout($bar.data('timeoutId'));
            var timeoutId = setTimeout(function() {
                $bar.fadeOut();
            }, 4000);
            $bar.data('timeoutId', timeoutId);
        }

        window.loyaltyShopHandleClick = function(button) {
            var $btn = $(button);
            var $container = $btn.closest('.loyalty-product-container');
            var $skuInput = $container.find('.product-sku');

            var sku = $skuInput.data('product-sku') || $skuInput.attr('data-product-sku');
            var type = $skuInput.data('type') || $skuInput.attr('data-type');
            var rawDiscount = $skuInput.data('price') || $skuInput.attr('data-price');

            if (!sku || !type) {
                showLoyaltyMessageBar('Missing SKU or type.', false);
                return false;
            }

            if (!window.loyaltyShopCustomerId) {
                showLoyaltyMessageBar('Please log in to add this product to your cart.', false);
                return false;
            }

            // Make request to lightweight frontend controllers
            if (type === 'discount_code') {
                var discount = parseFloat(rawDiscount);
                if (isNaN(discount)) {
                    showLoyaltyMessageBar('Invalid discount value.', false);
                    return false;
                }

                var endpointUrl = '/loyaltyshop/discount/claim';

                fetch(endpointUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ discount: discount, sku: sku })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    showLoyaltyMessageBar(data.message, data.success);
                })
                .catch(function(err) {
                    showLoyaltyMessageBar('Discount request failed. See console.', false);
                    console.error('Discount request error:', err);
                });

            } else {
                var endpointUrl = '/loyaltyshop/cart/add';

                fetch(endpointUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ sku: sku })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    showLoyaltyMessageBar(data.message, data.success);
                })
                .catch(function(err) {
                    showLoyaltyMessageBar('Add to cart failed. See console.', false);
                    console.error('Add to cart error:', err);
                });
            }

            return false;
        };

        function initDynamicButtons() {
            var buttons = document.querySelectorAll('button.le-add-to-cart-button:not([data-loyalty-init])');

            buttons.forEach(function(btn) {
                btn.setAttribute('data-loyalty-init', 'true');
                btn.setAttribute('onclick', 'return window.loyaltyShopHandleClick(this);');
            });
        }

        // Initialize buttons
        initDynamicButtons();

        // Observe for new buttons
        var observer = new MutationObserver(function() {
            initDynamicButtons();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        // Get customer ID and authentication token from Magento
        function getCustomerData() {
            try {
                // Try to get from Magento's customer data if available
                if (typeof require !== 'undefined') {
                    require(['Magento_Customer/js/customer-data'], function(customerData) {
                        customerData.get('customer').subscribe(function(customer) {
                            if (customer && customer.id) {
                                window.loyaltyShopCustomerId = customer.id;
                            }
                        });

                        var current = customerData.get('customer')();
                        if (current && current.id) {
                            window.loyaltyShopCustomerId = current.id;
                        }
                    });
                } else {
                    // Fallback: try to get from cookies or other methods
                    console.log('RequireJS not available, customer ID detection may not work');
                }
            } catch (e) {
                console.log('Error getting customer ID:', e);
            }
        }

        // Get customer token for authentication
        window.loyaltyShopCustomerToken = null;
        
        function getCustomerToken() {
            return new Promise(function(resolve, reject) {
                if (window.loyaltyShopCustomerToken) {
                    resolve(window.loyaltyShopCustomerToken);
                    return;
                }
                
                // Try to get existing token from session storage
                var storedToken = sessionStorage.getItem('loyalty_customer_token');
                if (storedToken) {
                    window.loyaltyShopCustomerToken = storedToken;
                    resolve(storedToken);
                    return;
                }
                
                // If no token, try to create one (this requires customer to be logged in)
                if (!window.loyaltyShopCustomerId) {
                    reject('Customer not logged in');
                    return;
                }
                
                // For anonymous endpoints, we don't actually need a token
                // Just resolve with null to indicate anonymous access
                resolve(null);
            });
        }

        // Get form key for fallback authentication
        function getFormKey() {
            try {
                var formKeyMeta = document.querySelector('meta[name="form_key"]');
                if (formKeyMeta) {
                    return formKeyMeta.getAttribute('content');
                }
                
                var formKeyInput = document.querySelector('input[name="form_key"]');
                if (formKeyInput) {
                    return formKeyInput.value;
                }
                
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.indexOf('form_key=') === 0) {
                        return cookie.substring('form_key='.length);
                    }
                }
                
                return null;
            } catch (e) {
                console.error('Error getting form key:', e);
                return null;
            }
        }

        // Get authentication headers
        function getAuthHeaders(token) {
            var headers = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };
            
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
            
            var formKey = getFormKey();
            if (formKey) {
                headers['X-Magento-Form-Key'] = formKey;
            }
            
            return headers;
        }

        getCustomerData();
        
        // ===== CART OBSERVER FUNCTIONALITY =====
        
        function isCartPage() {
            return window.location.href.indexOf('checkout/cart') !== -1;
        }
        
        if (isCartPage()) {
            function disableQtyForFreeProducts() {
                $('.cart.item:not(.loyalty-qty-locked)').each(function() {
                    var $item = $(this);
                    
                    var dataLocked = $item.attr('data-loyalty-locked-qty');
                    var isLocked = (dataLocked === 'true' || dataLocked === '1');
                    
                    if (isLocked) {
                        var qtySelectors = [
                            '.input-text.qty',
                            'input[name^="cart"][name$="[qty]"]'
                        ];
                        
                        for (var j = 0; j < qtySelectors.length; j++) {
                            var $qtyInput = $item.find(qtySelectors[j]);
                            if ($qtyInput.length) {
                                $qtyInput.prop('disabled', true);
                                $qtyInput.css('pointer-events', 'none');
                                $qtyInput.css('background-color', '#f0f0f0');
                                $qtyInput.attr('readonly', 'readonly');
                                
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease, .qty-modifier').css('pointer-events', 'none');
                                $item.find('.qty-changer, .qty-button, .qty-increase, .qty-decrease, .qty-modifier').css('opacity', '0.5');
                                
                                $item.addClass('loyalty-qty-locked');
                            }
                        }
                    }
                });
            }
            
            // ===== LUMA QUANTITY FIX =====
            
            function disableQtyInputsForLoyaltyProducts() {
                $('.cart.item').each(function() {
                    var $item = $(this);
                    
                    var dataLocked = $item.attr('data-loyalty-locked-qty');
                    var isLocked = (dataLocked === 'true' || dataLocked === '1');
                    
                    if (isLocked) {
                        var $qtyInput = $item.find('.input-text.qty');
                        
                        if ($qtyInput.length) {
                            try {
                                var nameAttr = $qtyInput.attr('name');
                                if (nameAttr) {
                                    var matches = nameAttr.match(/cart\[(\d+)\]/);
                                    if (matches && matches.length > 1) {
                                        var itemId = matches[1];
                                        var qty = $qtyInput.val();
                                        
                                        var $qtyContainer = $qtyInput.closest('.control.qty');
                                        
                                        var $hiddenInput = $('<input>')
                                            .attr('type', 'hidden')
                                            .attr('name', 'cart[' + itemId + '][qty]')
                                            .attr('value', qty);
                                        
                                        var $staticQty = $('<span>')
                                            .addClass('qty-static')
                                            .text('Qty: ' + qty)
                                            .css({
                                                'font-weight': 'bold',
                                                'display': 'block',
                                                'margin-top': '7px'
                                            });
                                        
                                        $qtyInput.replaceWith($hiddenInput);
                                        $qtyContainer.prepend($staticQty);
                                        
                                        $item.addClass('loyalty-qty-locked');
                                        return;
                                    }
                                }
                            } catch (e) {
                                console.error('Error processing quantity input:', e);
                            }
                            
                            // Fallback
                            $qtyInput.prop('disabled', true)
                                .css('background-color', '#f0f0f0')
                                .css('pointer-events', 'none')
                                .attr('readonly', 'readonly');
                            
                            $item.addClass('loyalty-qty-locked');
                        }
                    }
                });
            }
            
            // Run cart-specific functions
            $(document).ready(function() {
                disableQtyForFreeProducts();
                disableQtyInputsForLoyaltyProducts();
            });
            
            // Handle AJAX updates
            $(document).on('ajax:updateCartItemQty', disableQtyForFreeProducts);
            $(document).on('ajax:updateCart', disableQtyForFreeProducts);
            
            $(document).on('ajaxComplete', function(event, xhr, settings) {
                if (settings.url.indexOf('/checkout/cart/') !== -1) {
                    setTimeout(function() {
                        disableQtyForFreeProducts();
                        disableQtyInputsForLoyaltyProducts();
                    }, 500);
                }
            });
            
            // MutationObserver for cart changes
            if (typeof MutationObserver !== 'undefined') {
                var cartContainers = [
                    '.cart.main',
                    '.cart-container',
                    '#shopping-cart-table',
                    '.checkout-cart-index'
                ];
                
                var targetNode = null;
                
                for (var i = 0; i < cartContainers.length; i++) {
                    var container = document.querySelector(cartContainers[i]);
                    if (container) {
                        targetNode = container;
                        break;
                    }
                }
                
                if (targetNode) {
                    var config = { attributes: false, childList: true, subtree: true };
                    
                    var cartObserver = new MutationObserver(function(mutationsList, observer) {
                        clearTimeout(window.loyaltyQtyObserverTimeout);
                        window.loyaltyQtyObserverTimeout = setTimeout(function() {
                            disableQtyForFreeProducts();
                            disableQtyInputsForLoyaltyProducts();
                        }, 200);
                    });
                    
                    cartObserver.observe(targetNode, config);
                }
            }
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLoyaltyShop);
    } else {
        initLoyaltyShop();
    }
})();
</script>
```

## Required CSS Styles

Add this CSS to your theme or via PageBuilder:

```css
/* Styles for locked quantity inputs */
.loyalty-qty-locked .input-text.qty,
.loyalty-qty-locked .input-qty,
.loyalty-qty-locked input[name^="cart"][name$="[qty]"] {
    background-color: #f0f0f0 !important;
    border-color: #cccccc !important;
    color: #999999 !important;
    cursor: not-allowed !important;
    pointer-events: none !important;
    opacity: 0.7 !important;
}

/* Disable +/- buttons */
.loyalty-qty-locked .qty-changer,
.loyalty-qty-locked .qty-button,
.loyalty-qty-locked .qty-increase,
.loyalty-qty-locked .qty-decrease,
.loyalty-qty-locked .qty-modifier {
    pointer-events: none !important;
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}

/* Add a visual indicator */
.loyalty-qty-locked:after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 0;
    height: 0;
    border-style: solid;
    border-width: 0 20px 20px 0;
    border-color: transparent #f0f0f0 transparent transparent;
    z-index: 1;
}

/* Message bar styles */
#loyalty-message-bar {
    font-family: Arial, sans-serif;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
```

## HTML Structure Requirements

For loyalty products, ensure your HTML follows this structure:

```html
<div class="loyalty-product-container">
    <div class="product-sku" 
         data-product-sku="PRODUCT_SKU_HERE" 
         data-type="product" 
         data-price="0">
    </div>
    <button class="le-add-to-cart-button">Add to Cart</button>
</div>
```

For discount codes:

```html
<div class="loyalty-product-container">
    <div class="product-sku" 
         data-product-sku="DISCOUNT_CODE_SKU" 
         data-type="discount_code" 
         data-price="10.00">
    </div>
    <button class="le-add-to-cart-button">Claim Discount</button>
</div>
```

For cart items that should have locked quantities, ensure the cart item has:

```html
<tr class="cart item" data-loyalty-locked-qty="true">
    <!-- cart item content -->
</tr>
```

## API Endpoints

The JavaScript now uses lightweight frontend controllers instead of REST API:

1. **Add to Cart**: `POST /loyaltyshop/cart/add`
   - Body: `{"sku": "PRODUCT_SKU"}`
   - Uses Magento session authentication (no tokens required)

2. **Claim Discount**: `POST /loyaltyshop/discount/claim`
   - Body: `{"discount": 10.00, "sku": "DISCOUNT_SKU"}`
   - Uses Magento session authentication (no tokens required)

### Benefits of Frontend Controllers:
- ✅ **No authentication issues**: Uses Magento's built-in session system
- ✅ **Perfect for Spotler Activate**: Works in personalization contexts
- ✅ **Lightweight**: Minimal overhead compared to REST API
- ✅ **Reliable**: No form key or token complications

## Configuration Options

You can customize the behavior by modifying these variables at the top of the script:

- **Message display duration**: Change `4000` in the `setTimeout` call
- **Message bar styling**: Modify the CSS properties in `showLoyaltyMessageBar`
- **Button selector**: Change `.le-add-to-cart-button` to match your button class
- **Container selector**: Change `.loyalty-product-container` to match your structure

## Troubleshooting

1. **401 Unauthorized Error**: The script now includes proper authentication headers (form key). If you still get 401 errors, ensure:
   - The customer is logged in
   - Form key is available in the page (meta tag, input field, or cookie)
   - The API endpoints are configured as anonymous in webapi.xml

2. **Customer ID not detected**: Ensure Magento's customer data is properly loaded
   - Check browser console for RequireJS availability
   - Verify customer is logged in
   - Test with `console.log(window.loyaltyShopCustomerId)` after page load

3. **Buttons not working**: Check that the HTML structure matches the requirements
   - Ensure buttons have class `le-add-to-cart-button`
   - Verify container has class `loyalty-product-container`
   - Check that data attributes are properly set

4. **Cart quantity not locking**: Verify that cart items have the `data-loyalty-locked-qty="true"` attribute
   - This should be set by the backend PHP code
   - Check the cart item HTML in browser inspector

5. **AJAX updates not working**: The script handles most common AJAX patterns, but custom implementations may need additional event handlers

6. **Form key issues**: If authentication still fails:
   - Check if form key is present: `console.log(document.querySelector('meta[name="form_key"]'))`
   - Verify the form key is being sent in request headers
   - Consider adding custom form key retrieval logic for your specific setup

## Browser Compatibility

This script is compatible with:
- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+

For older browsers, you may need to add polyfills for:
- `fetch()` API
- `MutationObserver`
- `forEach()` on NodeList
