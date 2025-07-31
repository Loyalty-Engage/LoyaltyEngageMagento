define(['jquery', 'Magento_Customer/js/customer-data'], function ($, customerData) {
  'use strict';

  return function () {
    window.loyaltyShopCustomerId = null;
    window.loyaltyShopMessageConfig = null;

    // Load message configuration
    function loadMessageConfig() {
      if (window.loyaltyShopMessageConfig) {
        return Promise.resolve(window.loyaltyShopMessageConfig);
      }

      return fetch('/loyaltyengage_loyaltyshop/config/messages')
        .then(response => response.json())
        .then(config => {
          window.loyaltyShopMessageConfig = config;
          return config;
        })
        .catch(error => {
          console.error('Failed to load message config:', error);
          // Return default config
          return {
            success: {
              message: 'Product successfully added to cart!',
              backgroundColor: '#28a745',
              textColor: '#ffffff'
            },
            error: {
              message: 'There was an error adding the product to cart.',
              backgroundColor: '#ffc107',
              textColor: '#212529'
            }
          };
        });
    }

    function showLoyaltyMessageBar(message, isSuccess = true) {
      loadMessageConfig().then(config => {
        // Remove any existing message bars first
        $('#loyalty-message-bar').remove();
        
        const messageConfig = isSuccess ? config.success : config.error;
        const displayMessage = message || messageConfig.message;
        
        // Create new message bar
        const $bar = $('<div id="loyalty-message-bar"></div>').css({
          position: 'fixed',
          top: 0,
          left: 0,
          width: '100%',
          padding: '12px 20px',
          'text-align': 'center',
          'font-weight': 'bold',
          'font-size': '16px',
          'z-index': 10000,
          background: messageConfig.backgroundColor,
          color: messageConfig.textColor,
          borderBottom: '2px solid ' + messageConfig.backgroundColor,
          cursor: 'pointer',
          display: 'none'
        }).text(displayMessage);
        
        // Add click to dismiss functionality
        $bar.on('click', function() {
          $(this).fadeOut(300, function() {
            $(this).remove();
          });
        });
        
        // Add to body and show
        $('body').prepend($bar);
        $bar.fadeIn(300);
        
        // Auto-hide after 4 seconds
        const timeoutId = setTimeout(() => {
          $bar.fadeOut(300, function() {
            $(this).remove();
          });
        }, 4000);
        
        // Store timeout ID for potential cleanup
        $bar.data('timeoutId', timeoutId);
      });
    }

    window.loyaltyShopHandleClick = function (button) {
      const $btn = $(button);
      const $container = $btn.closest('.loyalty-product-container');
      const $skuInput = $container.find('.product-sku');

      const sku = $skuInput.data('product-sku') || $skuInput.attr('data-product-sku');
      const type = $skuInput.data('type') || $skuInput.attr('data-type');
      const rawDiscount = $skuInput.data('price') || $skuInput.attr('data-price');

      if (!sku || !type) {
        showLoyaltyMessageBar('Missing SKU or type.', false);
        return false;
      }

      if (!window.loyaltyShopCustomerId) {
        // Only show the message when a customer tries to add a product to the cart
        showLoyaltyMessageBar('Please log in to add this product to your cart.', false);
        return false;
      }

      if (type === 'discount_code') {
        const discount = parseFloat(rawDiscount);
        if (isNaN(discount)) {
          showLoyaltyMessageBar('Invalid discount value.', false);
          return false;
        }

        const endpointUrl = `/rest/V1/loyalty/discount/${window.loyaltyShopCustomerId}/claim-after-cart`;

        fetch(endpointUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ discount, sku })
        })
          .then(res => res.json())
          .then(data => {
            showLoyaltyMessageBar(data.message, data.success);
          })
          .catch(err => {
            showLoyaltyMessageBar('Discount request failed. See console.', false);
          });

      } else {
        const endpointUrl = `/rest/V1/loyalty/shop/${window.loyaltyShopCustomerId}/cart/add`;

        fetch(endpointUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ sku })
        })
          .then(res => res.json())
          .then(data => {
            showLoyaltyMessageBar(data.message, data.success);
          })
          .catch(err => {
            showLoyaltyMessageBar('Add to cart failed. See console.', false);
          });
      }

      return false;
    };

    function initDynamicButtons() {
      const buttons = document.querySelectorAll('button.le-add-to-cart-button:not([data-loyalty-init])');

      buttons.forEach((btn, i) => {
        btn.setAttribute('data-loyalty-init', 'true');
        btn.setAttribute('onclick', 'return window.loyaltyShopHandleClick(this);');
      });
    }

    initDynamicButtons();

    const observer = new MutationObserver(() => {
      initDynamicButtons();
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });

    try {
      customerData.get('customer').subscribe(function (customer) {
        if (customer && customer.id) {
          window.loyaltyShopCustomerId = customer.id;
        }
      });

      const current = customerData.get('customer')();
      if (current && current.id) {
        window.loyaltyShopCustomerId = current.id;
      }
    } catch (e) {
    }
  };
});
