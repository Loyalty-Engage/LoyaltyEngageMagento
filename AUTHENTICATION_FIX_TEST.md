# Authentication Fix Test Script

## 🔧 **Issue Fixed**

The review export and free shipping features were failing with HTTP 401 errors because they were using the wrong authentication method.

### **Problem**
- **Working Code**: Uses Basic Authentication with `tenant_id:bearer_token`
- **New Code (Before Fix)**: Was trying to use Bearer token with `client_id` and `client_secret`

### **Solution Applied**
Updated both `ReviewConsumer.php` and `LoyaltyTierChecker.php` to use the same Basic Authentication pattern as the working cart code.

## 🧪 **Test the Fix**

### **Step 1: Test Review Export**
```bash
# Run the review consumer to process the queued review
bin/magento queue:consumers:start loyaltyshop_review_event_consumer --max-messages=1
```

**Expected Result:**
```
[LOYALTY-REVIEW-CONSUMER] Review exported successfully - ID: 347, Customer: customer@example.com
```

### **Step 2: Test Free Shipping**
1. **Enable Free Shipping**: Go to Admin → Stores → Configuration → Loyalty Engage → Loyalty Tier Free Shipping
   - Set **Enable Free Shipping for Loyalty Tiers**: `Yes`
   - Set **Free Shipping Tiers**: `Bronze;Silver;Gold` (or your actual tiers)
   - Save Configuration

2. **Test Checkout**: 
   - Login as a customer
   - Add product to cart
   - Go to checkout
   - Check shipping methods for "(Loyalty Free Shipping)" text

### **Step 3: Check Logs**
```bash
# Monitor logs for success messages
tail -f var/log/system.log | grep "LOYALTY-"

# Expected log entries:
# [LOYALTY-TIER] Tier fetched for customer@example.com: Gold
# [LOYALTY-SHIPPING] Free shipping applied - Method: flatrate_flatrate
```

## 🔍 **Authentication Details**

### **Fixed Authentication Pattern**
```php
// Get credentials (these are actually tenant_id and bearer_token)
$tenantId = $this->loyaltyHelper->getClientId();
$bearerToken = $this->loyaltyHelper->getClientSecret();

// Create Basic Auth header (same as working cart code)
$authString = base64_encode($tenantId . ':' . $bearerToken);
$headers['Authorization'] = 'Basic ' . $authString;
```

### **API Endpoints**
- **Review Export**: `POST /api/v1/events`
- **Tier Checking**: `GET /api/v1/contact/{email}/loyalty_status`

Both now use the same Basic Authentication as the working cart functionality.

## ✅ **Success Criteria**

The fix is successful if:
1. **Review Export**: No more 401 errors, reviews export successfully
2. **Free Shipping**: Tier checking works, free shipping applied to qualifying customers
3. **Logs**: Clear success messages in system.log
4. **Performance**: Features work with minimal impact (cached tier checking)

## 🚀 **Next Steps**

After confirming the fix works:
1. Clear any failed queue messages if needed
2. Monitor the features in production
3. Adjust cache duration if needed for optimal performance

The authentication issue should now be completely resolved!
