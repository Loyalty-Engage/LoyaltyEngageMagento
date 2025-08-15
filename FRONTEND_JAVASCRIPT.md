# Frontend JavaScript Code for LoyaltyShop Plugin

This document contains the frontend JavaScript code that was removed from the LoyaltyShop plugin. This code can be loaded via PageBuilder or other frontend methods as needed.

## RequireJS Configuration

The following RequireJS configuration was used to define the JavaScript modules:

```javascript
var config = {
    map: {
        '*': {
            'loyalty-cart': 'LoyaltyEngage_LoyaltyShop/js/loyalty-cart',
            'loyalty-cart-observer': 'LoyaltyEngage_LoyaltyShop/js/loyalty-cart-observer',
            'luma-qty-fix': 'LoyaltyEngage_LoyaltyShop/js/luma-qty-fix'
        }
    }
};
```

## Main Loyalty Cart JavaScript (loyalty-cart.js)

```javascript
define([
    'jquery',
    'mage/url',
    'mage/storage',
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function ($, urlBuilder, storage, customerData, $t) {
    'use strict';

    return {
        /**
         * Initialize loyalty cart functionality
         */
        init: function() {
            this.bindEvents();
            this.observeCartChanges();
        },

        /**
         * Bind cart events
         */
        bindEvents: function() {
            var self = this;
            
            // Observe quantity input changes
            $(document).on('change', 'input[name*="[qty]"]', function() {
                var $input = $(this);
                var $cartItem = $input.closest('.cart.item');
                
                if ($cartItem.attr('data-loyalty-locked-qty') === 'true') {
                    self.handleLoyaltyQtyChange($input, $cartItem);
                }
            });

            // Observe remove item clicks
            $(document).on('click', '.action.action-delete', function(e) {
                var $link = $(this);
                var $cartItem = $link.closest('.cart.item');
                
                if ($cartItem.attr('data-loyalty-locked-qty') === 'true') {
                    self.handleLoyaltyItemRemove(e, $link, $cartItem);
                }
            });
        },

        /**
         * Handle loyalty product quantity changes
         */
        handleLoyaltyQtyChange: function($input, $cartItem) {
            var originalQty = $input.data('original-qty') || $input.val();
            
            // Prevent quantity changes for loyalty products
            $input.val(originalQty);
            
            this.showMessage($t('Quantity cannot be changed for loyalty products.'), 'notice');
        },

        /**
         * Handle loyalty product removal
         */
        handleLoyaltyItemRemove: function(event, $link, $cartItem) {
            event.preventDefault();
            
            var productName = $cartItem.find('.product-item-name').text().trim();
            
            if (confirm($t('Are you sure you want to remove this loyalty product: %1?').replace('%1', productName))) {
                // Allow removal to proceed
                window.location.href = $link.attr('href');
            }
        },

        /**
         * Observe cart data changes
         */
        observeCartChanges: function() {
            var cart = customerData.get('cart');
            
            cart.subscribe(function(cartData) {
                if (cartData && cartData.items) {
                    this.processCartItems(cartData.items);
                }
            }.bind(this));
        },

        /**
         * Process cart items to identify loyalty products
         */
        processCartItems: function(items) {
            var self = this;
            
            $.each(items, function(index, item) {
                if (item.loyalty_locked_qty) {
                    self.markLoyaltyItem(item.item_id);
                }
            });
        },

        /**
         * Mark cart item as loyalty product
         */
        markLoyaltyItem: function(itemId) {
            var $cartItem = $('[data-cart-item="' + itemId + '"]');
            
            if ($cartItem.length) {
                $cartItem.attr('data-loyalty-locked-qty', 'true');
                
                // Add visual indicator
                $cartItem.addClass('loyalty-product');
                
                // Store original quantity
                var $qtyInput = $cartItem.find('input[name*="[qty]"]');
                if ($qtyInput.length) {
                    $qtyInput.data('original-qty', $qtyInput.val());
                }
            }
        },

        /**
         * Show message to user
         */
        showMessage: function(message, type) {
            type = type || 'success';
            
            // Create message element
            var $message = $('<div class="message ' + type + '">' +
                '<div>' + message + '</div>' +
                '</div>');
            
            // Find message container or create one
            var $container = $('.page.messages');
            if (!$container.length) {
                $container = $('<div class="page messages"></div>');
                $('.page-wrapper').prepend($container);
            }
            
            // Add message
            $container.append($message);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $message.fadeOut(function() {
                    $message.remove();
                });
            }, 5000);
        }
    };
});
```

## Loyalty Cart Observer JavaScript (loyalty-cart-observer.js)

```javascript
define([
    'jquery',
    'Magento_Customer/js/customer-data'
], function ($, customerData) {
    'use strict';

    return {
        /**
         * Initialize observer
         */
        init: function() {
            this.observeCartSection();
        },

        /**
         * Observe cart section changes
         */
        observeCartSection: function() {
            var cart = customerData.get('cart');
            
            cart.subscribe(function(cartData) {
                this.processCartUpdate(cartData);
            }.bind(this));
        },

        /**
         * Process cart update
         */
        processCartUpdate: function(cartData) {
            if (!cartData || !cartData.items) {
                return;
            }

            var self = this;
            
            // Wait for DOM to be ready
            setTimeout(function() {
                self.updateLoyaltyItems(cartData.items);
            }, 100);
        },

        /**
         * Update loyalty items in the cart
         */
        updateLoyaltyItems: function(items) {
            var self = this;
            
            $.each(items, function(index, item) {
                if (item.loyalty_locked_qty) {
                    self.processLoyaltyItem(item);
                }
            });
        },

        /**
         * Process individual loyalty item
         */
        processLoyaltyItem: function(item) {
            var $cartItem = $('[data-cart-item="' + item.item_id + '"]');
            
            if ($cartItem.length) {
                // Mark as loyalty product
                $cartItem.attr('data-loyalty-locked-qty', 'true');
                $cartItem.addClass('loyalty-product');
                
                // Lock quantity input
                var $qtyInput = $cartItem.find('input[name*="[qty]"]');
                if ($qtyInput.length) {
                    $qtyInput.prop('readonly', true);
                    $qtyInput.addClass('loyalty-locked');
                }
                
                // Add loyalty indicator
                this.addLoyaltyIndicator($cartItem);
            }
        },

        /**
         * Add visual loyalty indicator
         */
        addLoyaltyIndicator: function($cartItem) {
            if ($cartItem.find('.loyalty-indicator').length === 0) {
                var $indicator = $('<span class="loyalty-indicator">Loyalty Product</span>');
                $cartItem.find('.product-item-name').append($indicator);
            }
        }
    };
});
```

## Luma Theme Quantity Fix JavaScript (luma-qty-fix.js)

```javascript
define([
    'jquery'
], function ($) {
    'use strict';

    return {
        /**
         * Initialize Luma-specific fixes
         */
        init: function() {
            this.fixQuantityControls();
        },

        /**
         * Fix quantity controls for Luma theme
         */
        fixQuantityControls: function() {
            $(document).ready(function() {
                // Fix quantity increment/decrement buttons
                $('.cart.item[data-loyalty-locked-qty="true"]').each(function() {
                    var $item = $(this);
                    
                    // Disable quantity controls
                    $item.find('.qty-inc, .qty-dec').prop('disabled', true);
                    $item.find('.qty-inc, .qty-dec').addClass('disabled');
                    
                    // Add click prevention
                    $item.find('.qty-inc, .qty-dec').on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    });
                });
            });
        }
    };
});
```

## CSS Styles

The following CSS styles should be included for proper styling:

```css
.loyalty-product {
    border-left: 3px solid #ff6600;
}

.loyalty-product .qty input.loyalty-locked {
    background-color: #f5f5f5;
    cursor: not-allowed;
}

.loyalty-indicator {
    background: #ff6600;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    margin-left: 10px;
    text-transform: uppercase;
}

.loyalty-product .qty-inc.disabled,
.loyalty-product .qty-dec.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
```

## Usage Instructions

To implement this JavaScript code via PageBuilder or other methods:

1. **Via PageBuilder**: Create a custom HTML block and include the JavaScript code wrapped in `<script>` tags with proper RequireJS dependencies.

2. **Via Layout XML**: Add the JavaScript files to your theme and reference them in layout XML files.

3. **Via Custom Module**: Create a custom module that includes these JavaScript files and loads them on appropriate pages.

4. **Direct Implementation**: Include the JavaScript code directly in your templates or custom blocks.

## Integration Example

Here's an example of how to integrate this code via PageBuilder:

```html
<script>
require([
    'jquery',
    'mage/url',
    'mage/storage',
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function ($, urlBuilder, storage, customerData, $t) {
    'use strict';
    
    // Include the loyalty cart JavaScript code here
    var loyaltyCart = {
        // ... (include the loyalty-cart.js code here)
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        loyaltyCart.init();
    });
});
</script>
```

## Notes

- This JavaScript code was originally integrated into the Magento module structure
- The code handles loyalty product quantity locking and removal confirmation
- It integrates with Magento's customer data sections for real-time cart updates
- The code includes Luma theme-specific fixes for quantity controls
- All user-facing messages support Magento's translation system
