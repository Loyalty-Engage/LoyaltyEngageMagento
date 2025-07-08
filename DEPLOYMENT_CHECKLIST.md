# Magento Commerce Compatibility Deployment Checklist

## Pre-Deployment Verification

### 1. Backup Current Environment
- [ ] Create full database backup
- [ ] Backup current module files
- [ ] Document current module version/state

### 2. Environment Information
- [ ] Confirm Magento edition: `bin/magento --version`
- [ ] Check enabled modules: `bin/magento module:status | grep -i loyalty`
- [ ] Verify B2B modules: `bin/magento module:status | grep -i b2b`

## File Deployment

### 1. New Files to Deploy
- [ ] `Helper/EnterpriseDetection.php` - New enterprise detection helper
- [ ] `ENTERPRISE_COMPATIBILITY.md` - Documentation
- [ ] `DEPLOYMENT_CHECKLIST.md` - This checklist

### 2. Modified Files to Deploy
- [ ] `Plugin/CheckoutCartItemRendererPlugin.php` - Enhanced with Enterprise compatibility
- [ ] `ViewModel/CartItemHelper.php` - Enhanced with Enterprise compatibility  
- [ ] `etc/di.xml` - Updated plugin priority

### 3. File Verification
Verify these files contain the NEW enhanced code (not old versions):

**`Plugin/CheckoutCartItemRendererPlugin.php`** should contain:
- [ ] `use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;`
- [ ] `shouldSkipLoyaltyProcessing()` check
- [ ] `isLoyaltyProduct()` private method

**`ViewModel/CartItemHelper.php`** should contain:
- [ ] `use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;`
- [ ] Constructor with `EnterpriseDetection` parameter
- [ ] `shouldSkipLoyaltyProcessing()` check

**`etc/di.xml`** should contain:
- [ ] `sortOrder="100"` in the cart item renderer plugin

## Deployment Commands

### 1. Clear All Caches
```bash
bin/magento cache:clean
bin/magento cache:flush
```

### 2. Recompile Dependencies
```bash
bin/magento setup:di:compile
```

### 3. Deploy Static Content (if needed)
```bash
bin/magento setup:static-content:deploy
```

### 4. Verify Module Status
```bash
bin/magento module:status LoyaltyEngage_LoyaltyShop
```

## Post-Deployment Testing

### 1. Basic Functionality Test
- [ ] Add regular product to cart
- [ ] Verify price displays correctly (not 0)
- [ ] Verify quantity selector is editable
- [ ] Verify cart total is correct

### 2. Loyalty Product Test (if applicable)
- [ ] Add loyalty product to cart
- [ ] Verify quantity is locked
- [ ] Verify price shows as 0 or locked
- [ ] Verify cart total includes loyalty product correctly

### 3. Mixed Cart Test
- [ ] Add both regular and loyalty products
- [ ] Verify regular products show prices
- [ ] Verify loyalty products remain locked
- [ ] Verify cart total is correct

### 4. B2B Exclusion Test (Enterprise only)
If B2B modules are enabled:
- [ ] Test with B2B customer account
- [ ] Verify plugin is skipped (no loyalty behavior)
- [ ] Verify normal Magento cart behavior

## Debug Information Collection

### 1. Enable Debug Logging (Optional)
Add to `app/etc/env.php`:
```php
'dev' => [
    'debug' => [
        'debug_logging' => true
    ]
]
```

### 2. Check Log Files
Monitor for any errors in:
- [ ] `var/log/system.log`
- [ ] `var/log/exception.log`
- [ ] `var/log/debug.log`

### 3. Browser Console Check
- [ ] Open browser developer tools
- [ ] Check for JavaScript errors in console
- [ ] Verify no failed AJAX requests

## Rollback Plan (If Issues Occur)

### 1. Quick Rollback Files
If issues occur, restore these files to previous versions:
- [ ] `Plugin/CheckoutCartItemRendererPlugin.php`
- [ ] `ViewModel/CartItemHelper.php`
- [ ] `etc/di.xml`

### 2. Remove New Files
- [ ] Delete `Helper/EnterpriseDetection.php`

### 3. Clear Caches After Rollback
```bash
bin/magento cache:clean
bin/magento setup:di:compile
```

## Success Criteria

### Community Edition
- [ ] ✅ Regular products show correct prices
- [ ] ✅ Loyalty products remain locked (if applicable)
- [ ] ✅ No regression in existing functionality
- [ ] ✅ Cart totals are accurate

### Enterprise Edition
- [ ] ✅ Regular products show correct prices (not 0)
- [ ] ✅ Quantity selectors work for regular products
- [ ] ✅ Loyalty products remain locked (if applicable)
- [ ] ✅ B2B customers unaffected (plugin skipped)
- [ ] ✅ No JavaScript errors
- [ ] ✅ Cart totals are accurate

## Common Issues & Solutions

### Issue: "Class not found" errors
**Solution**: Run `bin/magento setup:di:compile`

### Issue: Regular products still showing 0 price
**Check**: 
- [ ] Files deployed correctly
- [ ] Caches cleared
- [ ] No orphaned loyalty flags in database

### Issue: Plugin not executing
**Check**:
- [ ] Module enabled: `bin/magento module:status`
- [ ] DI compiled: `bin/magento setup:di:compile`
- [ ] Check logs for errors

### Issue: B2B functionality broken
**Check**:
- [ ] B2B exclusion logic working
- [ ] Enable debug logging to verify plugin is skipped for B2B

## Contact Information

If issues persist after following this checklist:
1. Enable debug logging
2. Collect log files
3. Document specific error messages
4. Note which test scenarios fail
5. Provide environment details (Magento version, enabled modules)

## Final Verification

- [ ] All tests pass
- [ ] No error logs
- [ ] Performance acceptable
- [ ] Stakeholders approve functionality
- [ ] Documentation updated

**Deployment Complete**: ✅ / ❌

**Notes**:
_Space for deployment notes, issues encountered, or additional observations_
