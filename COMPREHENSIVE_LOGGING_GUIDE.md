# Comprehensive Loyalty Shop Logging Guide

## Overview
This guide covers the comprehensive logging system implemented for the LoyaltyShop module to track when loyalty products vs regular products are added to the cart and help diagnose issues where regular products are incorrectly shown as loyalty products.

## New Logging Components

### 1. Centralized Logger Helper (`Helper/Logger.php`)
- **Purpose**: Standardized logging across the entire module
- **Features**: 
  - Consistent log format: `[LOYALTY-SHOP] [COMPONENT] [ACTION] - Message`
  - Specialized methods for different types of logging
  - Configurable debug logging
  - Context-rich logging with structured data

### 2. Cart Addition Observer (`Observer/CartProductAddObserver.php`)
- **Purpose**: Track every product addition to cart
- **Triggers**: `checkout_cart_product_add_after` event
- **Logs**: Product type, customer, source, detection methods, environment context

### 3. Cart Page View Observer (`Observer/CartPageViewObserver.php`)
- **Purpose**: Log all products in cart when cart page is viewed
- **Triggers**: `layout_load_before` event (filtered for cart page)
- **Logs**: Cart overview, individual item details with prices and loyalty status, cart totals

### 3. Enhanced API Logging (`Model/LoyaltyCart.php`)
- **Purpose**: Track loyalty API interactions and product additions
- **Logs**: API calls, responses, loyalty flag setting, validation errors

### 4. Enhanced Plugin Logging (`Plugin/CheckoutCartItemRendererPlugin.php`)
- **Purpose**: Track loyalty product detection during cart display
- **Logs**: Detection methods, environment context, final results

### 5. Enhanced ViewModel Logging (`ViewModel/CartItemHelper.php`)
- **Purpose**: Track loyalty detection in templates
- **Logs**: Template-level loyalty detection results

## Log Format Structure

### Standard Format
```
[LOYALTY-SHOP] [COMPONENT] [ACTION] - Message with context
```

### Components
- `CART-ADD`: Cart addition operations
- `DETECTION`: Loyalty product detection
- `API`: API interactions with LoyaltyEngage
- `PLUGIN`: Plugin operations
- `OBSERVER`: Observer operations
- `VIEWMODEL`: ViewModel operations
- `QUEUE`: Queue operations

### Actions
- `LOYALTY`: Loyalty product operations
- `REGULAR`: Regular product operations
- `ERROR`: Error conditions
- `SUCCESS`: Successful operations
- `VALIDATION`: Validation operations
- `ENVIRONMENT`: Environment context

## Key Logging Points

### 1. Product Addition to Cart
**Location**: `Observer/CartProductAddObserver.php`
**Event**: `checkout_cart_product_add_after`

**Example Logs**:
```
[LOYALTY-SHOP] [CART-ADD] [LOYALTY] - Product SKU123 added via loyalty-api for customer@email.com
[LOYALTY-SHOP] [CART-ADD] [REGULAR] - Product SKU456 added via regular-cart for customer@email.com
```

**Context Data**:
- Product ID, name, price, quantity
- Customer email
- Quote ID and item ID
- Detection methods used
- Source of addition (loyalty-api, regular-cart, loyalty-frontend)

### 2. Loyalty API Interactions
**Location**: `Model/LoyaltyCart.php`

**Example Logs**:
```
[LOYALTY-SHOP] [API] [LOYALTY] - Starting loyalty product addition - Customer ID: 123, SKU: ABC123
[LOYALTY-SHOP] [API] [LOYALTY] - Calling LoyaltyEngage API for customer@email.com with SKU ABC123
[LOYALTY-SHOP] [API] [SUCCESS] - POST addToCart - 200: Success
[LOYALTY-SHOP] [API] [LOYALTY] - Loyalty flags set for product ABC123 - Custom price: 0, loyalty_locked_qty: 1
[LOYALTY-SHOP] [API] [SUCCESS] - Successfully processed loyalty product ABC123 for customer@email.com
```

### 3. Loyalty Detection
**Location**: `Plugin/CheckoutCartItemRendererPlugin.php`, `ViewModel/CartItemHelper.php`

**Example Logs**:
```
[LOYALTY-SHOP] [DETECTION] [ENVIRONMENT] - Environment: Enterprise=Yes | B2B=No | Store=default
[LOYALTY-SHOP] [DETECTION] [LOYALTY] - Product "Free T-Shirt" (SKU123) detected as LOYALTY via option_check
[LOYALTY-SHOP] [DETECTION] [REGULAR] - Product "Regular T-Shirt" (SKU456) detected as REGULAR via all_methods_failed
```

### 4. Cart Page View Logging
**Location**: `Observer/CartPageViewObserver.php`
**Event**: `layout_load_before` (cart page only)

**Example Logs**:
```
[LOYALTY-SHOP] [CART] [REGULAR] - Cart page viewed - 3 total items (1 loyalty, 2 regular)
[LOYALTY-SHOP] [CART] [LOYALTY] - Cart item: Free T-Shirt (SKU123) - Qty: 1, Price: $0.00, Type: LOYALTY
[LOYALTY-SHOP] [CART] [REGULAR] - Cart item: Regular T-Shirt (SKU456) - Qty: 2, Price: $25.00, Type: REGULAR
[LOYALTY-SHOP] [CART] [REGULAR] - Cart totals - Subtotal: $50.00, Grand Total: $50.00, Items: 3
```

**Context Data**:
- Cart overview with loyalty vs regular counts
- Individual item details (ID, name, SKU, quantity, prices)
- Detection methods used for each item
- Cart totals and applied coupons

### 5. Environment Context
**Logged Once Per Session**:
```
[LOYALTY-SHOP] [DETECTION] [ENVIRONMENT] - Environment: Enterprise=Yes | B2B=Yes | Store=default
```

## How to Use the Logging System

### 1. Enable Logging
The logging system is always active, but you can enable debug logging by adding this to your store configuration:
```xml
<config>
    <default>
        <loyalty>
            <general>
                <debug_logging>1</debug_logging>
            </general>
        </loyalty>
    </default>
</config>
```

### 2. Monitor Logs
**Real-time monitoring**:
```bash
tail -f var/log/system.log | grep "LOYALTY-SHOP"
```

**Filter by component**:
```bash
tail -f var/log/system.log | grep "LOYALTY-SHOP.*CART-ADD"
tail -f var/log/system.log | grep "LOYALTY-SHOP.*API"
tail -f var/log/system.log | grep "LOYALTY-SHOP.*DETECTION"
```

**Filter by action**:
```bash
tail -f var/log/system.log | grep "LOYALTY-SHOP.*LOYALTY"
tail -f var/log/system.log | grep "LOYALTY-SHOP.*REGULAR"
tail -f var/log/system.log | grep "LOYALTY-SHOP.*ERROR"
```

### 3. Testing Scenarios

#### Test 1: Add Regular Product
1. Add a regular product to cart via normal "Add to Cart" button
2. Go to cart page
3. Check logs for:
   ```
   [LOYALTY-SHOP] [CART-ADD] [REGULAR] - Product SKU added via regular-cart
   [LOYALTY-SHOP] [DETECTION] [REGULAR] - Product detected as REGULAR
   ```

#### Test 2: Add Loyalty Product
1. Use loyalty API to add product
2. Check logs for:
   ```
   [LOYALTY-SHOP] [API] [LOYALTY] - Starting loyalty product addition
   [LOYALTY-SHOP] [API] [SUCCESS] - POST addToCart - 200: Success
   [LOYALTY-SHOP] [API] [LOYALTY] - Loyalty flags set for product
   [LOYALTY-SHOP] [CART-ADD] [LOYALTY] - Product added via loyalty-api
   [LOYALTY-SHOP] [DETECTION] [LOYALTY] - Product detected as LOYALTY
   ```

#### Test 3: Cart Page View
1. Add both regular and loyalty products to cart
2. Navigate to cart page
3. Check logs for:
   ```
   [LOYALTY-SHOP] [CART] [REGULAR] - Cart page viewed - 3 total items (1 loyalty, 2 regular)
   [LOYALTY-SHOP] [CART] [LOYALTY] - Cart item: Free Item (SKU123) - Qty: 1, Price: $0.00, Type: LOYALTY
   [LOYALTY-SHOP] [CART] [REGULAR] - Cart item: Regular Item (SKU456) - Qty: 2, Price: $25.00, Type: REGULAR
   [LOYALTY-SHOP] [CART] [REGULAR] - Cart totals - Subtotal: $50.00, Grand Total: $50.00, Items: 3
   ```

#### Test 4: Environment Detection
1. Load any cart page
2. Check for environment log (appears once):
   ```
   [LOYALTY-SHOP] [DETECTION] [ENVIRONMENT] - Environment: Enterprise=Yes | B2B=No
   ```

## Troubleshooting with Logs

### Issue: Regular Products Showing as Loyalty
**Look for**: Which detection method is incorrectly returning PASS
```bash
grep "Detection Method.*PASS" var/log/system.log
```

**Common causes**:
- Method 1 PASS: Product has `loyalty_locked_qty` option when it shouldn't
- Method 2 PASS: Product has `loyalty_locked_qty` data when it shouldn't  
- Method 3 PASS: Product has loyalty flag in additional_options
- Method 4 PASS: Product itself has loyalty attribute set

### Issue: Loyalty Products Not Being Detected
**Look for**: All detection methods returning FAIL for loyalty products
```bash
grep "LOYALTY.*Detection Method.*FAIL" var/log/system.log
```

### Issue: API Problems
**Look for**: API error responses
```bash
grep "LOYALTY-SHOP.*API.*ERROR" var/log/system.log
```

### Issue: Environment Problems
**Look for**: Environment detection issues
```bash
grep "LOYALTY-SHOP.*ENVIRONMENT" var/log/system.log
```

## Log Analysis Examples

### Successful Loyalty Product Flow
```
[LOYALTY-SHOP] [API] [LOYALTY] - Starting loyalty product addition - Customer ID: 123, SKU: ABC123
[LOYALTY-SHOP] [API] [SUCCESS] - POST addToCart - 200: Success
[LOYALTY-SHOP] [API] [LOYALTY] - Loyalty flags set for product ABC123
[LOYALTY-SHOP] [CART-ADD] [LOYALTY] - Product ABC123 added via loyalty-api for customer@email.com
[LOYALTY-SHOP] [DETECTION] [LOYALTY] - Product "Free Item" (ABC123) detected as LOYALTY via option_check
```

### Problem: Regular Product Incorrectly Flagged
```
[LOYALTY-SHOP] [CART-ADD] [REGULAR] - Product XYZ789 added via regular-cart for customer@email.com
[LOYALTY-SHOP] [DETECTION] [LOYALTY] - Product "Regular Item" (XYZ789) detected as LOYALTY via data_check
```
**Issue**: Regular product has loyalty data set incorrectly

### API Rejection
```
[LOYALTY-SHOP] [API] [LOYALTY] - Calling LoyaltyEngage API for customer@email.com with SKU ABC123
[LOYALTY-SHOP] [API] [ERROR] - POST addToCart - 401: User not eligible
[LOYALTY-SHOP] [API] [ERROR] - LoyaltyEngage API rejected product ABC123 for customer@email.com
```

## Performance Considerations

### Log Volume
- The system generates detailed logs for every cart interaction
- Consider log rotation for high-traffic sites
- Debug logging can be disabled in production

### Log Cleanup
```bash
# Clean old logs (keep last 7 days)
find var/log -name "*.log" -mtime +7 -delete

# Archive logs
gzip var/log/system.log.1
```

## Configuration Options

### Disable Debug Logging
```xml
<config>
    <default>
        <loyalty>
            <general>
                <debug_logging>0</debug_logging>
            </general>
        </loyalty>
    </default>
</config>
```

### Custom Log File (Optional)
You can modify the logger to write to a separate file by extending the Logger helper.

## Next Steps

1. **Deploy the enhanced logging system**
2. **Test with both regular and loyalty products**
3. **Monitor logs during the problematic scenario**
4. **Identify which detection method is causing issues**
5. **Implement targeted fixes based on log analysis**
6. **Reduce logging verbosity once issue is resolved**

This comprehensive logging system will help you pinpoint exactly why regular products are being treated as loyalty products in your problematic environment.
