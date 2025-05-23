# Hyva Theme Integration for LoyaltyEngage LoyaltyShop

This document outlines how the LoyaltyEngage LoyaltyShop module has been integrated with both Luma and Hyva themes, with a focus on preventing quantity changes for free products in the shopping cart.

## Overview

The module now supports both Luma and Hyva themes through a multi-layered approach:

1. **Template-level integration** - Custom templates for both themes
2. **JavaScript-based protection** - Theme-specific and generic scripts
3. **Server-side validation** - Backend protection regardless of theme
4. **CSS styling** - Visual indicators for locked quantity inputs

## Implementation Details

### Layout Configuration

- **Luma Theme**: Uses `checkout_cart_index.xml` for cart-specific functionality
- **Hyva Theme**: Uses `hyva_theme_checkout_cart_index.xml` for Hyva-specific cart rendering

### JavaScript Components

The module includes several JavaScript files that work together:

1. **loyalty-cart-observer.js**: Core functionality that works across themes
   - Uses multiple selectors to identify price elements in different themes
   - Sets up MutationObserver to detect DOM changes
   - Handles AJAX updates for both themes

2. **luma-qty-fix.js**: Luma-specific implementation
   - Replaces quantity inputs with static text and hidden inputs
   - Handles Luma-specific DOM structure

3. **hyva-disable-qty-for-free-products.js**: Hyva-specific implementation
   - Targets Hyva theme's unique selectors
   - Listens for Hyva-specific events

4. **disable-qty-for-free-products.js**: Legacy implementation for backward compatibility

### Performance Optimizations

To improve performance and reduce console noise:

1. **Selective script loading** to optimize performance:

   a. **Global vs. Page-specific scripts**: Separated scripts into global (loaded everywhere) and page-specific (loaded only on cart pages)
   ```javascript
   // view/frontend/requirejs-config.js - Global script configuration
   var config = {
       map: {
           '*': {
               // This script should be loaded on every page
               'loyalty-cart': 'LoyaltyEngage_LoyaltyShop/js/loyalty-cart'
               
               // The following scripts are loaded conditionally via the template
               // only on the cart page, so we don't map them here
           }
       }
   };
   ```
   
   ```xml
   <!-- view/frontend/layout/default.xml - Load loyalty-cart.js on every page -->
   <referenceContainer name="before.body.end">
       <block class="Magento\Framework\View\Element\Template" name="loyaltyshop.loyalty.cart.js" template="Magento_Theme::js/require_js.phtml">
           <arguments>
               <argument name="js_name" xsi:type="string">loyalty-cart</argument>
           </arguments>
       </block>
   </referenceContainer>
   ```

   b. **Page-specific loading**: Scripts are only loaded on the cart page via template condition
   ```php
   <script>
       // Only execute on cart pages
       if (window.location.href.indexOf('checkout/cart') !== -1) {
           // Use requirejs-config.js approach instead of direct require
           require.config({
               paths: {
                   'loyalty-cart-observer': 'LoyaltyEngage_LoyaltyShop/js/loyalty-cart-observer',
                   'luma-qty-fix': 'LoyaltyEngage_LoyaltyShop/js/luma-qty-fix',
                   'hyva-disable-qty': 'LoyaltyEngage_LoyaltyShop/js/hyva-disable-qty-for-free-products',
                   'disable-qty': 'LoyaltyEngage_LoyaltyShop/js/disable-qty-for-free-products'
               }
           });
           
           // Only load scripts on cart page
           require([
               'loyalty-cart-observer',
               'luma-qty-fix',
               'hyva-disable-qty',
               'disable-qty'
           ], function() {
               // Scripts will initialize themselves
           });
       }
   </script>
   ```

   c. **Module definition check**: Scripts check if they're on the cart page before defining the full module
   ```javascript
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
           // Full implementation here
       });
   }
   ```

   d. **Function-level check**: Even if the module is loaded, functions check if they're on the cart page
   ```javascript
   if (window.location.href.indexOf('checkout/cart') === -1) {
       return; // Not on cart page, don't run the script
   }
   ```

2. **Development-only logging**: Console logs are only shown in development environments
   ```javascript
   function shouldLog() {
       return window.location.hostname === 'localhost' || 
              window.location.hostname === '127.0.0.1' || 
              window.location.hostname.indexOf('.test') !== -1 || 
              window.location.hostname.indexOf('.local') !== -1;
   }
   ```

3. **Error handling**: All scripts include proper error handling to prevent JavaScript errors

### CSS Styling

The module includes CSS styles that work across both themes:

```css
.loyalty-qty-locked .qty-input,
.loyalty-qty-locked .input-text.qty {
    background-color: #f0f0f0 !important;
    pointer-events: none !important;
    border-color: #cccccc !important;
}

.loyalty-qty-locked .qty-changer,
.loyalty-qty-locked .qty-button {
    opacity: 0.5 !important;
    pointer-events: none !important;
}
```

## Testing

The implementation has been tested on:

1. Luma theme with free products in cart
2. Hyva theme with free products in cart
3. Mixed cart with both free and regular products
4. Various page loads and AJAX updates

## Troubleshooting

If quantity inputs for free products can still be changed:

1. Check browser console for any JavaScript errors
2. Verify that the product price is correctly set to 0
3. Check if the CSS classes are being applied correctly
4. Verify that the server-side validation is working as expected

## Future Improvements

Potential future enhancements:

1. Add configuration option to customize the static text shown for quantity
2. Implement a more elegant visual indicator for locked quantities
3. Add support for additional themes beyond Luma and Hyva
