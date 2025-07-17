# Loyalty Cart Logging Test Guide

## Overview
The plugin now includes comprehensive logging to help diagnose why regular products are being treated as loyalty products on Enterprise Edition. All logs are written to `var/log/system.log` with the prefix `[LOYALTY-CART]`.

## How to Test

### 1. Deploy the Enhanced Code
```bash
# Clear caches and recompile
bin/magento cache:clean
bin/magento cache:flush
bin/magento setup:di:compile
```

### 2. Test Regular Product Addition
1. **Add a regular product to cart** (using normal "Add to Cart" button)
2. **Go to cart page** (this triggers the plugin logging)
3. **Check the logs immediately**:
   ```bash
   tail -f var/log/system.log | grep "LOYALTY-CART"
   ```

### 3. What to Look For in Logs

#### **Environment Detection (First Log Entry)**
```
[LOYALTY-CART] Environment: Enterprise=Yes | B2B Enabled=Yes/No
```
This confirms if Magento Commerce (formerly Enterprise Edition) is detected correctly.

#### **For Each Cart Item, You'll See:**

**Item Analysis:**
```
[LOYALTY-CART] Item Analysis - Product: "Regular T-Shirt" (ID: 123)
[LOYALTY-CART] Price: $25.00 | Options Count: 2 | Enterprise: Yes | B2B: No
```

**Detection Method Results:**
```
[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): FAIL - no option found
[LOYALTY-CART] Detection Method 2 (item data): FAIL - data value: null
[LOYALTY-CART] Detection Method 3 (additional_options): FAIL - no additional options
[LOYALTY-CART] Detection Method 4 (Enterprise fallback): FAIL - no product loyalty flag
```

**All Item Options (for debugging):**
```
[LOYALTY-CART] Item Options: None found
```
OR
```
[LOYALTY-CART] All Item Options:
[LOYALTY-CART] - Option Code: "info_buyRequest" | Value: "{"uenc":"aHR0cHM6Ly9..."
[LOYALTY-CART] - Option Code: "product_qty_123" | Value: "1"
```

**Final Result:**
```
[LOYALTY-CART] FINAL RESULT: REGULAR PRODUCT - Normal display for: Regular T-Shirt
```

## Diagnosing the Issue

### **If Regular Products Are Incorrectly Flagged as Loyalty:**

Look for which detection method is returning `PASS` when it should return `FAIL`:

#### **Method 1 PASS (Unexpected):**
```
[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): PASS - option value: 1
```
**Issue**: Regular product has `loyalty_locked_qty` option set
**Solution**: Check how products are being added to cart

#### **Method 2 PASS (Unexpected):**
```
[LOYALTY-CART] Detection Method 2 (item data): PASS - data value: 1
```
**Issue**: Regular product has `loyalty_locked_qty` data field set
**Solution**: Check product data or cart item creation process

#### **Method 3 PASS (Unexpected):**
```
[LOYALTY-CART] Detection Method 3 (additional_options): PASS - found loyalty flag in additional options
```
**Issue**: Regular product has loyalty flag in additional options
**Solution**: Check additional options being set during cart addition

#### **Method 4 PASS (Unexpected):**
```
[LOYALTY-CART] Detection Method 4 (Enterprise fallback): PASS - product has loyalty flag
```
**Issue**: Product itself has loyalty flag set
**Solution**: Check product attributes/data

### **If B2B Exclusion Isn't Working:**
```
[LOYALTY-CART] B2B Context - Plugin skipped for item: Product Name
```
Should appear for B2B customers, but plugin still processes regular customers.

## Expected Log Output Examples

### **Working Correctly (Regular Product):**
```
[LOYALTY-CART] Environment: Enterprise=Yes | B2B Enabled=Yes
[LOYALTY-CART] Item Analysis - Product: "Regular Product" (ID: 456)
[LOYALTY-CART] Price: $29.99 | Options Count: 1 | Enterprise: Yes | B2B: Yes
[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): FAIL - no option found
[LOYALTY-CART] Detection Method 2 (item data): FAIL - data value: null
[LOYALTY-CART] Detection Method 3 (additional_options): FAIL - no additional options
[LOYALTY-CART] Detection Method 4 (Enterprise fallback): FAIL - no product loyalty flag
[LOYALTY-CART] Item Options: None found
[LOYALTY-CART] FINAL RESULT: REGULAR PRODUCT - Normal display for: Regular Product
```

### **Working Correctly (Loyalty Product):**
```
[LOYALTY-CART] Item Analysis - Product: "Free Loyalty Item" (ID: 789)
[LOYALTY-CART] Price: $0.00 | Options Count: 2 | Enterprise: Yes | B2B: Yes
[LOYALTY-CART] Detection Method 1 (loyalty_locked_qty option): PASS - option value: 1
[LOYALTY-CART] FINAL RESULT: LOYALTY PRODUCT - Quantity locked for: Free Loyalty Item
```

### **B2B Exclusion Working:**
```
[LOYALTY-CART] B2B Context - Plugin skipped for item: Any Product
```

## Troubleshooting

### **No Log Entries Appear**
- Check if module is enabled: `bin/magento module:status LoyaltyEngage_LoyaltyShop`
- Check if DI is compiled: `bin/magento setup:di:compile`
- Check if you're looking at cart page (not product page)

### **Plugin Not Executing**
- Verify caches are cleared
- Check for compilation errors
- Verify plugin is registered in `etc/di.xml`

### **Logs Show Wrong Edition**
- Check Enterprise detection logic
- Verify Enterprise modules are enabled
- Check if it's actually Community Edition

## Next Steps After Testing

1. **Share the log output** showing the problematic behavior
2. **Identify which detection method** is incorrectly flagging regular products
3. **Implement targeted fix** based on the specific issue found
4. **Remove or reduce logging** once issue is resolved

The detailed logging will pinpoint exactly why regular products are being treated as loyalty products, making it much easier to implement the correct fix.
