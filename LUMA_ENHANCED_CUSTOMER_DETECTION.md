# Enhanced Luma Customer Detection - Production Fix

## 🚨 **Problem Solved**

The original Luma script fails with `Customer data from Magento: {}` because it only relies on one detection method. This enhanced version uses **7 different detection methods** with comprehensive fallbacks.

## 🚀 **Enhanced JavaScript with Robust Customer Detection**

Replace your current JavaScript with this production-ready version:

```html
<script>
(function() {
    'use strict';
    
    // Enhanced debugging for production
    window.loyaltyShopDebug = true; // Set to false in production
    
    function debugLog(message, data) {
        if (window.loyaltyShopDebug) {
            console.log('[🔍 LoyaltyShop Enhanced]', message, data || '');
        }
    }
    
    // Wait for DOM and jQuery to be ready
    function initLoyaltyShop() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initLoyaltyShop, 100);
            return;
        }
        
        var $ = jQuery;
        debugLog('✅ jQuery ready, initializing...');
        
        // Global variables
        window.loyaltyShopCustomerId = null;
        window.loyaltyShopCustomerEmail = null;
        window.loyaltyShopIsLoggedIn = false;
        
        // ===== ENHANCED CUSTOMER DETECTION =====
        
        function getCustomerData() {
            debugLog('🔍 Starting enhanced customer detection...');
            
            // Reset customer data
            window.loyaltyShopCustomerId = null;
            window.loyaltyShopCustomerEmail = null;
            window.loyaltyShopIsLoggedIn = false;
            
            // Method 1: Magento Customer Data (RequireJS)
            if (typeof require !== 'undefined') {
                try {
                    require(['Magento_Customer/js/customer-data'], function(customerData) {
                        debugLog('📦 Method 1: Checking RequireJS customer data...');
                        
                        // Subscribe to changes
                        customerData.get('customer').subscribe(function(customer) {
                            debugLog('📡 Customer data subscription update:', customer);
                            if (customer && customer.id) {
                                window.loyaltyShopCustomerId = customer.id;
                                window.loyaltyShopCustomerEmail = customer.email;
                                window.loyaltyShopIsLoggedIn = true;
                                debugLog('✅ Method 1 Success - Customer ID:', customer.id);
                            }
                        });

                        // Get current data
                        var current = customerData.get('customer')();
                        debugLog('📦 Current customer data:', current);
                        if (current && current.id) {
                            window.loyaltyShopCustomerId = current.id;
                            window.loyaltyShopCustomerEmail = current.email;
                            window.loyaltyShopIsLoggedIn = true;
                            debugLog('✅ Method 1 Success - Customer ID:', current.id);
                            return;
                        }
                        
                        // Force reload customer data if empty
                        if (!current || Object.keys(current).length === 0) {
                            debugLog('🔄 Method 1: Customer data empty, forcing reload...');
                            customerData.reload(['customer'], false);
                            
                            // Wait and check again
                            setTimeout(function() {
                                var reloaded = customerData.get('customer')();
                                debugLog('📦 Reloaded customer data:', reloaded);
                                if (reloaded && reloaded.id) {
                                    window.loyaltyShopCustomerId = reloaded.id;
                                    window.loyaltyShopCustomerEmail = reloaded.email;
                                    window.loyaltyShopIsLoggedIn = true;
                                    debugLog('✅ Method 1 Reload Success - Customer ID:', reloaded.id);
                                } else {
                                    debugLog('❌ Method 1 failed, trying fallback methods...');
                                    tryFallbackMethods();
                                }
                            }, 1000);
                        } else {
                            debugLog('❌ Method 1 failed, trying fallback methods...');
                            tryFallbackMethods();
                        }
                    });
                } catch (e) {
                    debugLog('❌ Method 1 error:', e.message);
                    tryFallbackMethods();
                }
            } else {
                debugLog('❌ RequireJS not available, trying fallback methods...');
                tryFallbackMethods();
            }
        }
        
        function tryFallbackMethods() {
            debugLog('🔄 Starting fallback detection methods...');
            
            // Method 2: Local Storage Detection
            try {
                debugLog('📦 Method 2: Checking localStorage...');
                var mageCacheStorage = localStorage.getItem('mage-cache-storage');
                if (mageCacheStorage) {
                    var cacheData = JSON.parse(mageCacheStorage);
                    debugLog('📦 LocalStorage cache data keys:', Object.keys(cacheData));
                    
                    if (cacheData.customer && cacheData.customer.id) {
                        window.loyaltyShopCustomerId = cacheData.customer.id;
                        window.loyaltyShopCustomerEmail = cacheData.customer.email;
                        window.loyaltyShopIsLoggedIn = true;
                        debugLog('✅ Method 2 Success - Customer ID:', cacheData.customer.id);
                        return;
                    }
                }
            } catch (e) {
                debugLog('❌ Method 2 error:', e.message);
            }
            
            // Method 3: Session Storage Detection
            try {
                debugLog('📦 Method 3: Checking sessionStorage...');
                var sessionData = sessionStorage.getItem('mage-cache-storage');
                if (sessionData) {
                    var sessionCacheData = JSON.parse(sessionData);
                    if (sessionCacheData.customer && sessionCacheData.customer.id) {
                        window.loyaltyShopCustomerId = sessionCacheData.customer.id;
                        window.loyaltyShopCustomerEmail = sessionCacheData.customer.email;
                        window.loyaltyShopIsLoggedIn = true;
                        debugLog('✅ Method 3 Success - Customer ID:', sessionCacheData.customer.id);
                        return;
                    }
                }
            } catch (e) {
                debugLog('❌ Method 3 error:', e.message);
            }
            
            // Method 4: Cookie Detection
            try {
                debugLog('📦 Method 4: Checking cookies...');
                var cookies = document.cookie.split(';');
                var sectionDataIds = null;
                
                for (var i = 0; i < cookies.length; i++) {
                    var cookie = cookies[i].trim();
                    if (cookie.indexOf('section_data_ids=') === 0) {
                        sectionDataIds = decodeURIComponent(cookie.substring('section_data_ids='.length));
                        break;
                    }
                }
                
                if (sectionDataIds) {
                    debugLog('📦 Section data IDs cookie found:', sectionDataIds);
                    var sectionData = JSON.parse(sectionDataIds);
                    if (sectionData.customer) {
                        window.loyaltyShopCustomerId = 'logged_in'; // We know they're logged in
                        window.loyaltyShopIsLoggedIn = true;
                        debugLog('✅ Method 4 Success - Customer detected from section data');
                        return;
                    }
                }
            } catch (e) {
                debugLog('❌ Method 4 error:', e.message);
            }
            
            // Method 5: DOM Element Detection
            try {
                debugLog('📦 Method 5: Checking DOM elements...');
                var customerSelectors = [
                    '.customer-welcome',
                    '.customer-name',
                    '.header .customer',
                    '.customer-menu',
                    '[data-customer-id]',
                    '.logged-in',
                    '.customer-account'
                ];
                
                for (var i = 0; i < customerSelectors.length; i++) {
                    var element = document.querySelector(customerSelectors[i]);
                    if (element && element.textContent && element.textContent.trim()) {
                        // Check for customer ID in data attributes
                        var customerId = element.dataset.customerId || element.dataset.customer;
                        if (customerId) {
                            window.loyaltyShopCustomerId = customerId;
                            window.loyaltyShopIsLoggedIn = true;
                            debugLog('✅ Method 5 Success - Customer ID from DOM:', customerId);
                            return;
                        }
                        
                        // Element exists with content, assume logged in
                        window.loyaltyShopCustomerId = 'logged_in';
                        window.loyaltyShopIsLoggedIn = true;
                        debugLog('✅ Method 5 Success - Customer detected from DOM element:', customerSelectors[i]);
                        return;
                    }
                }
            } catch (e) {
                debugLog('❌ Method 5 error:', e.message);
            }
            
            // Method 6: Body Class Detection
            try {
                debugLog('📦 Method 6: Checking body classes...');
                var bodyClasses = document.body.className;
                if (bodyClasses.indexOf('customer-logged-in') !== -1 || 
                    bodyClasses.indexOf('logged-in') !== -1) {
                    window.loyaltyShopCustomerId = 'logged_in';
                    window.loyaltyShopIsLoggedIn = true;
                    debugLog('✅ Method 6 Success - Customer detected from body class');
                    return;
                }
            } catch (e) {
                debugLog('❌ Method 6 error:', e.message);
            }
            
            // Method 7: AJAX Session Check (last resort)
            debugLog('📦 Method 7: Performing AJAX session check...');
            performSessionCheck();
        }
        
        function performSessionCheck() {
            debugLog('🔄 Performing AJAX session validation...');
            
            fetch('/customer/section/load/?sections=customer', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
            .then(function(response) {
                debugLog('📡 Session check response status:', response.status);
                return response.json();
            })
            .then(function(data) {
                debugLog('📡 Session check response data:', data);
                if (data && data.customer && data.customer.id) {
                    window.loyaltyShopCustomerId = data.customer.id;
                    window.loyaltyShopCustomerEmail = data.customer.email;
                    window.loyaltyShopIsLoggedIn = true;
                    debugLog('✅ Method 7 Success - Customer ID from AJAX:', data.customer.id);
                } else if (data && data.customer && Object.keys(data.customer).length > 0) {
                    // Customer object exists but no ID, assume logged in
                    window.loyaltyShopCustomerId = 'logged_in';
                    window.loyaltyShopIsLoggedIn = true;
                    debugLog('✅ Method 7 Success - Customer detected from AJAX response');
                } else {
                    debugLog('❌ All methods failed - Customer not detected');
                }
            })
            .catch(function(error) {
                debugLog('❌ Method 7 error:', error.message);
                debugLog('❌ All methods failed - Customer not detected');
            });
        }
        
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
            
            debugLog('📢 Message shown:', message);
        }

        window.loyaltyShopHandleClick = function(button) {
            debugLog('🖱️ Button clicked:', button.textContent.trim());
            
            var $btn = $(button);
            var $container = $btn.closest('.loyalty-product-container');
            var $skuInput = $container.find('.product-sku');

            var sku = $skuInput.data('product-sku') || $skuInput.attr('data-product-sku');
            var type = $skuInput.data('type') || $skuInput.attr('data-type');
            var rawDiscount = $skuInput.data('price') || $skuInput.attr('data-price');

            debugLog('📦 Product data:', { sku: sku, type: type, rawDiscount: rawDiscount });

            if (!sku || !type) {
                showLoyaltyMessageBar('❌ Missing SKU or type.', false);
                return false;
            }

            // Enhanced customer check with retry
            if (!window.loyaltyShopIsLoggedIn && !window.loyaltyShopCustomerId) {
                debugLog('❌ Customer not detected, retrying detection...');
                showLoyaltyMessageBar('🔄 Checking login status...', true);
                
                // Retry customer detection
                getCustomerData();
                
                // Wait and check again
                setTimeout(function() {
                    if (!window.loyaltyShopIsLoggedIn && !window.loyaltyShopCustomerId) {
                        showLoyaltyMessageBar('❌ Please log in to add this product to your cart.', false);
                    } else {
                        debugLog('✅ Customer detected on retry, proceeding...');
                        processRequest(sku, type, rawDiscount);
                    }
                }, 2000);
                
                return false;
            }

            processRequest(sku, type, rawDiscount);
            return false;
        };
        
        function processRequest(sku, type, rawDiscount) {
            debugLog('🚀 Processing request:', { sku: sku, type: type });
            
            // Make request to lightweight frontend controllers
            if (type === 'discount_code') {
                var discount = parseFloat(rawDiscount);
                if (isNaN(discount)) {
                    showLoyaltyMessageBar('❌ Invalid discount value.', false);
                    return;
                }

                debugLog('💰 Claiming discount:', { sku: sku, discount: discount });
                showLoyaltyMessageBar('🔄 Claiming discount...', true);

                var endpointUrl = '/loyaltyshop/discount/claim';

                fetch(endpointUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ discount: discount, sku: sku })
                })
                .then(function(res) { 
                    debugLog('📡 Discount response status:', res.status);
                    return res.json(); 
                })
                .then(function(data) {
                    debugLog('📡 Discount response data:', data);
                    showLoyaltyMessageBar(data.message, data.success);
                })
                .catch(function(err) {
                    debugLog('❌ Discount request error:', err);
                    showLoyaltyMessageBar('❌ Discount request failed. Please try again.', false);
                });

            } else {
                debugLog('🛒 Adding to cart:', sku);
                showLoyaltyMessageBar('🔄 Adding to cart...', true);

                var endpointUrl = '/loyaltyshop/cart/add';

                fetch(endpointUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ sku: sku })
                })
                .then(function(res) { 
                    debugLog('📡 Add to cart response status:', res.status);
                    return res.json(); 
                })
                .then(function(data) {
                    debugLog('📡 Add to cart response data:', data);
                    showLoyaltyMessageBar(data.message, data.success);
                })
                .catch(function(err) {
                    debugLog('❌ Add to cart error:', err);
                    showLoyaltyMessageBar('❌ Add to cart failed. Please try again.', false);
                });
            }
        }

        function initDynamicButtons() {
            var buttons = document.querySelectorAll('button.le-add-to-cart-button:not([data-loyalty-init])');
            debugLog('🔘 Initializing buttons:', buttons.length);

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
                debugLog('❌ Error getting form key:', e);
                return null;
            }
        }

        // Start customer detection
        getCustomerData();
        
        // Re-check customer data every 30 seconds
        setInterval(function() {
            if (!window.loyaltyShopIsLoggedIn && !window.loyaltyShopCustomerId) {
                debugLog('🔄 Periodic customer check...');
                getCustomerData();
            }
        }, 30000);
        
        // Debug function for manual testing
        window.loyaltyShopTestCustomerDetection = function() {
            debugLog('=== 🧪 MANUAL CUSTOMER TEST ===');
            getCustomerData();
            setTimeout(function() {
                console.log('Customer ID:', window.loyaltyShopCustomerId);
                console.log('Customer Email:', window.loyaltyShopCustomerEmail);
                console.log('Is Logged In:', window.loyaltyShopIsLoggedIn);
                console.log('Form Key Available:', !!getFormKey());
            }, 3000);
        };
        
        debugLog('✅ Enhanced customer detection initialized');
        
        // ===== CART OBSERVER FUNCTIONALITY (unchanged) =====
        
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

## 🧪 **Testing the Enhanced Version**

### **Step 1: Replace Your JavaScript**
Copy the enhanced script above and replace your current implementation.

### **Step 2: Test Customer Detection**
Run this in your browser console:
```javascript
window.loyaltyShopTestCustomerDetection();
```

### **Step 3: Expected Results**
You should see detailed logging like:
```
[🔍 LoyaltyShop Enhanced] ✅ jQuery ready, initializing...
[🔍 LoyaltyShop Enhanced] 🔍 Starting enhanced customer detection...
[🔍 LoyaltyShop Enhanced] 📦 Method 1: Checking RequireJS customer data...
[🔍 LoyaltyShop Enhanced] 📦 Current customer data: {}
[🔍 LoyaltyShop Enhanced] 🔄 Method 1: Customer data empty, forcing reload...
[🔍 LoyaltyShop Enhanced] 📦 Reloaded customer data: {id: "1", email: "customer@example.com"}
[🔍 LoyaltyShop Enhanced] ✅ Method 1 Reload Success - Customer ID: 1
```

Or if Method 1 fails:
```
[🔍 LoyaltyShop Enhanced] ❌ Method 1 failed, trying fallback methods...
[🔍 LoyaltyShop Enhanced] 🔄 Starting fallback detection methods...
[🔍 LoyaltyShop Enhanced] 📦 Method 5: Checking DOM elements...
[🔍 LoyaltyShop Enhanced] ✅ Method 5 Success - Customer detected from DOM element: .customer-welcome
```

## 🎯 **Key Improvements**

### **1. 7 Detection Methods:**
- ✅ **Method 1:** RequireJS customer-data (with forced reload)
- ✅ **Method 2:** LocalStorage mage-cache-storage
- ✅ **Method 3:** SessionStorage mage-cache-storage  
- ✅ **Method 4:** Cookie section_data_ids parsing
- ✅ **Method 5:** DOM element detection (.customer-welcome, etc.)
- ✅ **Method 6:** Body class detection (customer-logged-in)
- ✅ **Method 7:** AJAX session validation (/customer/section/load)

### **2. Enhanced Error Handling:**
- ✅ **Retry mechanism** when customer data is empty
- ✅ **Graceful fallbacks** between methods
- ✅ **Detailed logging** for production debugging
- ✅ **Periodic re-checking** every 30 seconds

### **3. Production Features:**
- ✅ **Debug mode toggle** (`window.loyaltyShopDebug = false` for production)
- ✅ **Manual test function** (`window.loyaltyShopTestCustomerDetection()`)
- ✅ **Comprehensive logging** with emojis for easy identification
- ✅ **Session validation** as last resort

## 🚀 **Production Deployment**

1. **Replace your current script** with the enhanced version
2. **Test thoroughly** using the test function
3. **Set debug to false** for production: `window.loyaltyShopDebug = false`
4. **Monitor console logs** for any remaining issues

This enhanced version should completely resolve your "Customer data from Magento: {}" issue! 🎉
