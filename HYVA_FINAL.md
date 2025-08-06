# Hyva Theme Final Test & Implementation Guide

## 🎯 **Complete Solution Summary**

The Hyva theme compatibility issue has been resolved with:

1. **Enhanced Customer Detection** - Uses Hyva's `getBrowserStorage()` and multiple fallback methods
2. **Server-Side Authentication** - Controllers handle authentication via Magento sessions
3. **Improved Form Key Handling** - Uses Hyva's `getFormKey()` method
4. **Better Error Handling** - Graceful fallbacks and detailed logging

## 🧪 **Final Test Script for Spotler Activate**

Replace your current JavaScript with this enhanced version:

```html
<script>
(function() {
    'use strict';
    
    console.log('🚀 LoyaltyShop Hyva Final: Starting...');
    
    // Global variables
    window.loyaltyShopCustomerId = null;
    window.loyaltyShopCustomerEmail = null;
    window.loyaltyShopDebug = true;
    
    function debugLog(message, data) {
        if (window.loyaltyShopDebug) {
            console.log('[🚀 LoyaltyShop Final]', message, data || '');
        }
    }
    
    // Message bar function
    function showLoyaltyMessageBar(message, isSuccess) {
        isSuccess = isSuccess !== false;
        let bar = document.getElementById('loyalty-message-bar');
    
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'loyalty-message-bar';
            bar.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                padding: 12px 20px;
                text-align: center;
                font-weight: bold;
                font-size: 16px;
                z-index: 10000;
                display: none;
                transition: all 0.3s ease;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            `;
            document.body.appendChild(bar);
        }
    
        bar.style.background = isSuccess ? '#28a745' : '#ffc107';
        bar.style.color = isSuccess ? '#fff' : '#212529';
        bar.style.borderBottom = '2px solid ' + (isSuccess ? '#218838' : '#e0a800');
        bar.textContent = message;
        bar.style.display = 'block';
        bar.style.opacity = '1';
    
        if (bar.timeoutId) clearTimeout(bar.timeoutId);
        
        bar.timeoutId = setTimeout(() => {
            bar.style.opacity = '0';
            setTimeout(() => bar.style.display = 'none', 300);
        }, 4000);
        
        debugLog('Message shown:', message);
    }

    // Enhanced form key detection
    function getFormKey() {
        try {
            // Method 1: Hyva's getFormKey (preferred)
            if (window.hyva && window.hyva.getFormKey) {
                const formKey = window.hyva.getFormKey();
                if (formKey) {
                    debugLog('✅ Form key from Hyva:', formKey.substring(0, 8) + '...');
                    return formKey;
                }
            }
            
            // Method 2: Meta tag
            const formKeyMeta = document.querySelector('meta[name="form_key"]');
            if (formKeyMeta) {
                const formKey = formKeyMeta.getAttribute('content');
                debugLog('✅ Form key from meta:', formKey.substring(0, 8) + '...');
                return formKey;
            }
            
            // Method 3: Input field
            const formKeyInput = document.querySelector('input[name="form_key"]');
            if (formKeyInput) {
                const formKey = formKeyInput.value;
                debugLog('✅ Form key from input:', formKey.substring(0, 8) + '...');
                return formKey;
            }
            
            debugLog('❌ Form key not found');
            return null;
        } catch (e) {
            console.error('❌ Error getting form key:', e);
            return null;
        }
    }

    // Enhanced customer detection
    function getCustomerData() {
        try {
            debugLog('🔍 Starting enhanced customer detection...');
            
            // Reset customer data
            window.loyaltyShopCustomerId = null;
            window.loyaltyShopCustomerEmail = null;
            
            // Method 1: Hyva browser storage
            if (window.hyva && window.hyva.getBrowserStorage) {
                try {
                    const storage = window.hyva.getBrowserStorage();
                    const mageCacheStorage = storage.getItem('mage-cache-storage');
                    
                    if (mageCacheStorage) {
                        const cacheData = JSON.parse(mageCacheStorage);
                        debugLog('📦 Mage cache storage found:', Object.keys(cacheData));
                        
                        // Look for customer data
                        if (cacheData.customer) {
                            if (cacheData.customer.id) {
                                window.loyaltyShopCustomerId = cacheData.customer.id;
                                window.loyaltyShopCustomerEmail = cacheData.customer.email;
                                debugLog('✅ Customer from cache:', {
                                    id: cacheData.customer.id,
                                    email: cacheData.customer.email
                                });
                                return;
                            }
                        }
                        
                        // Check all keys for customer data
                        Object.keys(cacheData).forEach(key => {
                            if (key.includes('customer') && cacheData[key] && cacheData[key].id) {
                                window.loyaltyShopCustomerId = cacheData[key].id;
                                window.loyaltyShopCustomerEmail = cacheData[key].email;
                                debugLog('✅ Customer from ' + key + ':', cacheData[key]);
                            }
                        });
                    }
                } catch (e) {
                    debugLog('❌ Error parsing browser storage:', e.message);
                }
            }
            
            // Method 2: Section data cookie
            try {
                const sectionDataCookie = document.cookie
                    .split(';')
                    .find(c => c.trim().startsWith('section_data_ids='));
                    
                if (sectionDataCookie) {
                    const cookieValue = decodeURIComponent(sectionDataCookie.split('=')[1]);
                    const sectionData = JSON.parse(cookieValue);
                    
                    if (sectionData.customer) {
                        window.loyaltyShopCustomerId = 'logged_in';
                        debugLog('✅ Customer detected from section data cookie');
                        return;
                    }
                }
            } catch (e) {
                debugLog('❌ Error parsing section data cookie:', e.message);
            }
            
            // Method 3: DOM elements
            const customerSelectors = [
                '.customer-welcome',
                '.customer-name', 
                '[data-customer-id]',
                '.header .customer',
                '.customer-menu'
            ];
            
            for (let selector of customerSelectors) {
                const element = document.querySelector(selector);
                if (element && element.textContent && element.textContent.trim()) {
                    const customerId = element.dataset.customerId || element.dataset.customer;
                    if (customerId) {
                        window.loyaltyShopCustomerId = customerId;
                        debugLog('✅ Customer ID from DOM:', customerId);
                        return;
                    }
                    
                    // Element exists, assume logged in
                    window.loyaltyShopCustomerId = 'logged_in';
                    debugLog('✅ Customer detected from DOM element:', selector);
                    return;
                }
            }
            
            debugLog('ℹ️ Customer detection complete. ID:', window.loyaltyShopCustomerId || 'Not found');
            
        } catch (e) {
            console.error('❌ Error in customer detection:', e);
        }
    }

    // Main button handler
    window.loyaltyShopHandleClick = function(button) {
        debugLog('🖱️ Button clicked:', button.textContent.trim());
        
        const container = button.closest('.loyalty-product-container');
        if (!container) {
            showLoyaltyMessageBar('❌ Invalid product container.', false);
            return false;
        }
        
        const skuElement = container.querySelector('.product-sku');
        if (!skuElement) {
            showLoyaltyMessageBar('❌ Product SKU element not found.', false);
            return false;
        }

        const sku = skuElement.dataset.productSku || skuElement.getAttribute('data-product-sku');
        const type = skuElement.dataset.type || skuElement.getAttribute('data-type');
        const rawDiscount = skuElement.dataset.price || skuElement.getAttribute('data-price');

        debugLog('📦 Product data:', { sku, type, rawDiscount });

        if (!sku || !type) {
            showLoyaltyMessageBar('❌ Missing SKU or type.', false);
            return false;
        }

        // Process request (server will handle authentication)
        if (type === 'discount_code') {
            handleDiscountClaim(sku, rawDiscount);
        } else {
            handleAddToCart(sku);
        }

        return false;
    };

    function handleAddToCart(sku) {
        debugLog('🛒 Adding to cart:', sku);
        
        showLoyaltyMessageBar('🔄 Adding to cart...', true);

        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        
        const formKey = getFormKey();
        if (formKey) {
            headers['X-Magento-Form-Key'] = formKey;
        }

        fetch('/loyaltyshop/cart/add', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ sku: sku })
        })
        .then(response => {
            debugLog('📡 Add to cart response status:', response.status);
            return response.json();
        })
        .then(data => {
            debugLog('📡 Add to cart response:', data);
            showLoyaltyMessageBar(data.message, data.success);
            
            if (data.success) {
                triggerCartUpdate();
            }
        })
        .catch(error => {
            console.error('❌ Add to cart error:', error);
            showLoyaltyMessageBar('❌ Add to cart failed. Please try again.', false);
        });
    }

    function handleDiscountClaim(sku, rawDiscount) {
        const discount = parseFloat(rawDiscount);
        if (isNaN(discount)) {
            showLoyaltyMessageBar('❌ Invalid discount value.', false);
            return;
        }

        debugLog('💰 Claiming discount:', { sku, discount });
        
        showLoyaltyMessageBar('🔄 Claiming discount...', true);

        const headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        
        const formKey = getFormKey();
        if (formKey) {
            headers['X-Magento-Form-Key'] = formKey;
        }

        fetch('/loyaltyshop/discount/claim', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ discount: discount, sku: sku })
        })
        .then(response => {
            debugLog('📡 Discount response status:', response.status);
            return response.json();
        })
        .then(data => {
            debugLog('📡 Discount response:', data);
            showLoyaltyMessageBar(data.message, data.success);
        })
        .catch(error => {
            console.error('❌ Discount error:', error);
            showLoyaltyMessageBar('❌ Discount request failed. Please try again.', false);
        });
    }

    // Cart update for Hyva
    function triggerCartUpdate() {
        try {
            debugLog('🔄 Triggering cart update...');
            
            // Hyva cart events
            if (window.hyva && window.hyva.cart) {
                window.hyva.cart.updateCart();
                debugLog('✅ Hyva cart update triggered');
            }
            
            // Alpine.js events
            if (window.Alpine) {
                window.Alpine.nextTick(() => {
                    document.dispatchEvent(new CustomEvent('cart-updated'));
                    document.dispatchEvent(new CustomEvent('reload-customer-section-data'));
                });
                debugLog('✅ Alpine cart events triggered');
            }
            
            // Custom events
            ['loyalty-cart-updated', 'hyva-cart-updated'].forEach(eventName => {
                document.dispatchEvent(new CustomEvent(eventName, {
                    detail: { timestamp: Date.now() }
                }));
            });
            
            // Refresh mini cart
            const miniCart = document.querySelector('.minicart-wrapper, [x-data*="cart"]');
            if (miniCart && miniCart._x_dataStack && miniCart._x_dataStack[0].$refresh) {
                miniCart._x_dataStack[0].$refresh();
                debugLog('✅ Mini cart refreshed');
            }
            
        } catch (e) {
            debugLog('❌ Error triggering cart update:', e.message);
        }
    }

    // Button initialization
    function initDynamicButtons() {
        const buttons = document.querySelectorAll('button.le-add-to-cart-button:not([data-loyalty-init])');
        
        debugLog('🔘 Initializing buttons:', buttons.length);

        buttons.forEach(btn => {
            btn.setAttribute('data-loyalty-init', 'true');
            btn.setAttribute('onclick', 'return window.loyaltyShopHandleClick(this);');
            
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                return window.loyaltyShopHandleClick(this);
            });
        });
    }

    // Main initialization
    function initLoyaltyShop() {
        debugLog('🚀 Starting final initialization...');
        
        getCustomerData();
        initDynamicButtons();
        
        // Watch for new buttons
        const observer = new MutationObserver(() => {
            initDynamicButtons();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        // Re-check customer data every 10 seconds
        setInterval(getCustomerData, 10000);
        
        debugLog('✅ Final initialization complete');
    }

    // Wait for Hyva and Alpine
    function waitForHyva() {
        if (typeof window.hyva !== 'undefined' && typeof window.Alpine !== 'undefined') {
            debugLog('✅ Hyva and Alpine.js ready');
            setTimeout(initLoyaltyShop, 200);
        } else {
            debugLog('⏳ Waiting for Hyva and Alpine.js...', {
                hyva: typeof window.hyva !== 'undefined',
                alpine: typeof window.Alpine !== 'undefined'
            });
            setTimeout(waitForHyva, 100);
        }
    }
    
    // Start initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(waitForHyva, 100));
    } else {
        setTimeout(waitForHyva, 100);
    }
    
    // Debug functions
    window.loyaltyShopTestCustomerDetection = function() {
        debugLog('=== 🧪 MANUAL CUSTOMER TEST ===');
        getCustomerData();
        console.log('Customer ID:', window.loyaltyShopCustomerId);
        console.log('Customer Email:', window.loyaltyShopCustomerEmail);
        console.log('Form Key Available:', !!getFormKey());
    };
    
    debugLog('✅ Final script loaded');
})();
</script>
```

## 🎨 **Required CSS**

Add this CSS to your Hyva theme:

```css
/* Loyalty message bar */
#loyalty-message-bar {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    border-radius: 0 0 4px 4px;
}

/* Loyalty buttons */
.le-add-to-cart-button {
    transition: all 0.2s ease;
    cursor: pointer;
}

.le-add-to-cart-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Cart quantity locking */
.loyalty-qty-locked .input-text.qty,
.loyalty-qty-locked .qty-input,
.loyalty-qty-locked input[name*="[qty]"] {
    background-color: #f0f0f0 !important;
    border-color: #cccccc !important;
    color: #999999 !important;
    cursor: not-allowed !important;
    pointer-events: none !important;
    opacity: 0.7 !important;
}

.loyalty-qty-locked .qty-changer,
.loyalty-qty-locked .qty-button,
.loyalty-qty-locked [x-on\:click*="qty"],
.loyalty-qty-locked [\@click*="qty"] {
    pointer-events: none !important;
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}
```

## 🧪 **Testing Instructions**

### **Step 1: Implement the Script**
1. Copy the final JavaScript into Spotler Activate
2. Add the CSS to your Hyva theme
3. Clear Magento caches: `bin/magento cache:flush`

### **Step 2: Test Customer Detection**
Run in browser console:
```javascript
window.loyaltyShopTestCustomerDetection();
```

Expected output:
```
[🚀 LoyaltyShop Final] 🔍 Starting enhanced customer detection...
[🚀 LoyaltyShop Final] 📦 Mage cache storage found: ["messages", "customer", ...]
[🚀 LoyaltyShop Final] ✅ Customer detected from section data cookie
Customer ID: logged_in
Form Key Available: true
```

### **Step 3: Test Button Functionality**
1. Create a test button with this HTML:
```html
<div class="loyalty-product-container p-4 border rounded">
    <h3>🧪 Test Loyalty Product</h3>
    <div class="product-sku" 
         data-product-sku="TEST_SKU_123" 
         data-type="product" 
         data-price="0">
    </div>
    <button class="le-add-to-cart-button bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
        🛒 Test Add to Cart
    </button>
</div>
```

2. Click the button and check console logs
3. Should see detailed logging and proper API calls

### **Step 4: Verify Expected Results**

**✅ Successful Implementation:**
```
[🚀 LoyaltyShop Final] ✅ Hyva and Alpine.js ready
[🚀 LoyaltyShop Final] 🔍 Starting enhanced customer detection...
[🚀 LoyaltyShop Final] ✅ Customer detected from section data cookie
[🚀 LoyaltyShop Final] 🔘 Initializing buttons: 7
[🚀 LoyaltyShop Final] ✅ Final initialization complete
```

**✅ Button Click Success:**
```
[🚀 LoyaltyShop Final] 🖱️ Button clicked: Test Add to Cart
[🚀 LoyaltyShop Final] 📦 Product data: {sku: "TEST_SKU_123", type: "product", rawDiscount: "0"}
[🚀 LoyaltyShop Final] 🛒 Adding to cart: TEST_SKU_123
[🚀 LoyaltyShop Final] ✅ Form key from Hyva: a1b2c3d4...
[🚀 LoyaltyShop Final] 📡 Add to cart response status: 200
[🚀 LoyaltyShop Final] 📡 Add to cart response: {success: true, message: "Product added successfully"}
```

## 🎯 **Success Indicators**

- ✅ **Customer detection works** (even if ID is "logged_in")
- ✅ **Form key is found** using Hyva's method
- ✅ **Buttons initialize correctly** (7+ buttons found)
- ✅ **API calls succeed** (200 status responses)
- ✅ **Messages display properly** (success/error notifications)
- ✅ **Cart updates trigger** (Hyva-specific events)

## 🚀 **Production Deployment**

Once testing is successful:

1. **Disable debug mode**: Set `window.loyaltyShopDebug = false`
2. **Monitor logs**: Check `var/log/system.log` for any issues
3. **Test with real products**: Use actual SKUs from your catalog
4. **Verify cart functionality**: Ensure products are added correctly

This final solution should resolve all Hyva theme compatibility issues and make your loyalty add to cart functionality work perfectly with Spotler Activate! 🎉
