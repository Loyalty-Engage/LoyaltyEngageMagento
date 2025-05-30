# LoyaltyEngage LoyaltyShop Theme Support

This document explains how the LoyaltyEngage LoyaltyShop module supports both Luma and Hyvä themes.

## Architecture

The module uses a theme detection mechanism to conditionally load the appropriate frontend logic based on the active theme:

1. **Theme Detection**: The `Helper/Data.php` class includes an `isHyvaTheme()` method that checks if the current theme is Hyvä.

2. **ViewModel**: The `ViewModel/ThemeDetector.php` class makes theme detection available in templates.

3. **Layout Plugin**: The `Plugin/ThemeLayoutPlugin.php` automatically adds Hyvä-specific layout handles when Hyvä theme is active.

4. **Conditional Templates**: Templates check the active theme and include either Luma or Hyvä-specific implementations.

## File Structure

- **Luma-specific files** (original implementation):
  - `view/frontend/web/js/loyalty-cart.js`
  - `view/frontend/web/js/loyalty-cart-observer.js`
  - `view/frontend/web/js/luma-qty-fix.js`
  - `view/frontend/web/js/disable-qty-for-free-products.js`

- **Hyvä-specific files** (Alpine.js implementation):
  - `view/frontend/templates/hyva/loyalty-cart-js.phtml`
  - `view/frontend/templates/hyva/disable-qty-for-free-products.phtml`
  - `view/frontend/templates/hyva/loyalty-product-button.phtml`
  - `view/frontend/layout/hyva/default.xml`
  - `view/frontend/layout/hyva/checkout_cart_index.xml`

- **Shared files**:
  - `Helper/Data.php` (includes theme detection)
  - `ViewModel/ThemeDetector.php` (theme detection ViewModel)
  - `Plugin/ThemeLayoutPlugin.php` (layout handle plugin)
  
- **Controller**:
  - `Controller/Button/Render.php` (AJAX controller for rendering Hyvä buttons)
  - `etc/frontend/routes.xml` (route configuration)

## Implementation Details

### Luma Theme

The Luma implementation uses RequireJS and jQuery, following Magento 2's standard frontend architecture:

- JavaScript modules are loaded via RequireJS
- DOM manipulation is done with jQuery
- Event handling uses jQuery events

### Hyvä Theme

The Hyvä implementation uses Alpine.js and vanilla JavaScript, following Hyvä's frontend architecture:

- JavaScript functionality is implemented as Alpine.js components
- DOM manipulation uses vanilla JavaScript
- Event handling uses Alpine.js directives
- Styling uses Tailwind CSS classes

### Alpine.js Component Structure

For Hyvä, we use a self-contained Alpine.js component approach:

1. **Inline Component Definition**: Each button is wrapped in a div with an `x-data` attribute that contains the complete component logic:

   ```html
   <div x-data="{
       customerId: 123,
       
       handleClick(button) {
           // Button click logic here
       },
       
       showMessage(message, isSuccess) {
           // Message display logic here
       }
   }">
       <button x-on:click="handleClick($el)">Add to Cart</button>
   </div>
   ```

2. **Self-Contained Scope**: Each component has its own scope, ensuring that the `handleClick` method is always available within the component.

3. **Dynamic Button Handling**: For dynamically added buttons, we use a controller to render the complete component:

   ```
   Controller/Button/Render.php
   ```

   This ensures that even dynamically added buttons have the proper Alpine.js component structure.

### Key Differences

1. **JavaScript Loading**:
   - Luma: Uses RequireJS to load modules
   - Hyvä: Uses Alpine.js components initialized with `x-data`

2. **Event Handling**:
   - Luma: Uses jQuery event handlers
   - Hyvä: Uses Alpine.js `x-on:click` directives

3. **DOM Manipulation**:
   - Luma: Uses jQuery selectors and methods
   - Hyvä: Uses vanilla JavaScript methods

4. **Styling**:
   - Luma: Uses CSS classes and inline styles
   - Hyvä: Uses Tailwind CSS utility classes

## How It Works

1. When a page loads, the `ThemeLayoutPlugin` detects if Hyvä is active and adds appropriate layout handles.
2. Layout XML files include the appropriate templates based on the active theme.
3. Templates use the `ThemeDetector` ViewModel to conditionally include either Luma or Hyvä-specific code.
4. For Luma, the original RequireJS-based JavaScript is loaded.
5. For Hyvä, Alpine.js components are initialized with the equivalent functionality.

This approach ensures that the module works correctly with both themes while maintaining clean separation between the implementations.
