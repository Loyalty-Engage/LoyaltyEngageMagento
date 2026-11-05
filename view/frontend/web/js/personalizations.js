(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
    } else if (root.loyaltyEngagePersonalizations) {
        root.loyaltyEngagePersonalizations.scan();
    } else {
        root.loyaltyEngagePersonalizations = factory(root, root.document);
        root.loyaltyEngagePersonalizations.start();
    }
}(typeof window !== 'undefined' ? window : this, function (win, doc) {
    'use strict';
    var config, encodedConfig, rules = Object.create(null), last = new WeakMap(), sequence = 0, scheduled = false;
    var identifier, ready = false, loading = false, generation = 0, refreshTimer;
    var productPrice = null, cartValue = null, started = false, productListeners = {}, currentPrices;
    var variantSelected = false;
    var capabilities = {personalized: false, cart: false};
    var uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;

    function debug(message, key) {
        if (config && config.debug && win.console) {
            // Never log trigger bodies, private context, network responses or identifiers.
            win.console.debug('[Loyalty Engage]', message, key || '');
        }
    }

    function number(value) {
        return typeof value === 'number' && Number.isFinite(value) && value >= 0;
    }

    function convert(value, currency) {
        if (!number(value) || !/^[A-Z]{3}$/.test(config.currency || '')) { return null; }
        var rate = currency === config.currency ? 1 : currency === config.displayCurrency ? config.displayRate : null;
        return number(rate) && rate > 0 ? Math.round((value * rate + Number.EPSILON) * 100) / 100 : null;
    }

    function matches(rule) {
        if (rule.placement === 'manual') { return true; }
        if (rule.page_scope !== config.page.scope) { return false; }
        return rule.page_scope !== 'pages' || rule.pages.split(',').includes(config.page.path);
    }

    function targets() {
        if (!config) { return []; }
        return Array.from(doc.querySelectorAll('[data-le-personalization-key]')).filter(function (node) {
            var key = node.getAttribute('data-le-personalization-key'), rule = rules[key];
            if (!rule || !matches(rule)) { return false; }
            var visible = node.isConnected && node.getClientRects().length > 0;
            if (visible && win.getComputedStyle) { visible = win.getComputedStyle(node).visibility !== 'hidden'; }
            debug(visible ? 'target found' : 'target not visible', key);
            return visible;
        });
    }

    function targetId(node) {
        if (!/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(node.id || '') || doc.getElementById(node.id) !== node) {
            do { node.id = 'le-personalization-' + node.getAttribute('data-le-personalization-key') + '-mount-' + ++sequence; }
            while (doc.getElementById(node.id) !== node);
        }
        return node.id;
    }

    function ensureLoader() {
        win._lecloud = win._lecloud || {};
        win._lecloud.event = win._lecloud.event || [];
        win._lecloud.personalization = win._lecloud.personalization || [];
        if (typeof win._lecloud.personalization.push !== 'function') { return false; }
        var src = 'https://le-cloud.app/f/t/' + config.tenant + '.js';
        if (!doc.querySelector('script[src="' + src + '"]')) {
            var script = doc.createElement('script');
            script.type = 'module';
            script.async = true;
            script.src = src;
            script.addEventListener('error', function () { debug('loader failed (network or CSP)'); });
            doc.head.appendChild(script);
            debug('loader started');
        }
        return true;
    }

    function scan() {
        scheduled = false;
        if (!readConfig()) { return; }
        var nodes = targets();
        if (!nodes.length) { return; }
        var needPersonalized = nodes.some(function (node) { return !!rules[node.getAttribute('data-le-personalization-key')].personalized; });
        var needCart = ['cart', 'checkout'].includes(config.page.scope) && nodes.some(function (node) {
            return !!rules[node.getAttribute('data-le-personalization-key')].use_cart_value;
        });
        var needPrivate = needPersonalized || needCart;
        if (needPersonalized && !capabilities.personalized || needCart && !capabilities.cart) { ready = false; }
        if (needPrivate && !ready && !loading) { refresh(); }
        nodes.forEach(function (node) {
            var rule = rules[node.getAttribute('data-le-personalization-key')];
            if (!ready && (rule.personalized || (rule.use_cart_value && ['cart', 'checkout'].includes(config.page.scope)))) { return; }
            var trigger = {id: rule.public_id, target: '#' + targetId(node)};
            if (rule.personalized && identifier) { trigger.identifier = identifier; }
            if (rule.use_cart_value && /^[A-Z]{3}$/.test(config.currency || '')) {
                var value = config.page.scope === 'product' ? productPrice : cartValue;
                if (number(value)) { trigger.cartValue = value; }
            }
            var signature = JSON.stringify(trigger);
            if (last.get(node) === signature || !ensureLoader()) { return; }
            try {
                win._lecloud.personalization.push(trigger);
                last.set(node, signature);
                debug('event pushed', rule.key);
            } catch (error) {
                debug('personalization queue failed', rule.key);
            }
        });
    }

    function schedule() {
        if (!scheduled) {
            scheduled = true;
            (win.requestAnimationFrame || win.setTimeout)(scan);
        }
    }

    function identityChanged(next) {
        if (identifier === next) { return; }
        // Invalidate selectors of old in-flight personalized requests on login/logout.
        doc.querySelectorAll('[data-le-personalization-key]').forEach(function (node) {
            var rule = rules[node.getAttribute('data-le-personalization-key')];
            if (rule && rule.personalized) {
                node.replaceChildren();
                node.id = '';
            }
        });
        identifier = next;
    }

    function refresh() {
        if (!config) { return; }
        var nodes = targets();
        var personalized = nodes.some(function (node) { return !!rules[node.getAttribute('data-le-personalization-key')].personalized; });
        var cart = ['cart', 'checkout'].includes(config.page.scope) && nodes.some(function (node) {
            return !!rules[node.getAttribute('data-le-personalization-key')].use_cart_value;
        });
        if (!personalized && !cart) {
            ready = true;
            loading = false;
            capabilities = {personalized: false, cart: false};
            identityChanged(undefined);
            schedule();
            return;
        }
        ready = false;
        loading = true;
        var ticket = ++generation;
        var url;
        try {
            url = new URL(config.contextUrl, win.location.href);
            if (url.origin !== win.location.origin) { throw new Error('context origin'); }
            url.searchParams.set('personalized', personalized ? '1' : '0');
            url.searchParams.set('cart', cart ? '1' : '0');
        } catch (error) {
            finish({});
            return;
        }
        var timeout = win.setTimeout(function () { finish({}); }, 5000);
        function finish(data) {
            if (ticket !== generation || !loading) { return; }
            if (timeout) { win.clearTimeout(timeout); }
            loading = false;
            ready = true;
            capabilities = {personalized: personalized, cart: cart};
            // Only the same-origin session endpoint can supply identity; no DOM/storage/event identifiers.
            identityChanged(personalized && typeof data.identifier === 'string' && /^[a-f0-9]{64}$/.test(data.identifier)
                ? data.identifier : undefined);
            if (cart) { cartValue = number(data.cartValue) ? data.cartValue : null; }
            debug('private context refreshed');
            schedule();
        }
        if (typeof win.fetch !== 'function') { finish({}); return; }
        var pending;
        try {
            pending = win.fetch(url.href, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
        } catch (error) {
            debug('context unavailable');
            finish({});
            return;
        }
        Promise.resolve(pending)
            .then(function (response) {
                if (!response.ok || !String(response.headers.get('content-type')).includes('application/json')) {
                    throw new Error('context response');
                }
                return response.json();
            }).then(function (data) { finish(data && typeof data === 'object' ? data : {}); })
            .catch(function () { debug('context unavailable'); finish({}); });
    }

    function requestRefresh() {
        ++generation;
        ready = false;
        loading = true;
        win.clearTimeout(refreshTimer);
        refreshTimer = win.setTimeout(refresh, 100);
    }

    function prices(data) {
        if (!data || config.page.scope !== 'product') { return; }
        currentPrices = data;
        var price = data[config.includingTax ? 'finalPrice' : 'basePrice'];
        var selection = doc.querySelector('input[name="selected_configurable_option"]');
        var selected = config.product.type !== 'configurable' || variantSelected || !!(selection && selection.value);
        productPrice = selected && price && number(price.amount) ? convert(price.amount, config.displayCurrency) : null;
        debug('product context changed');
        schedule();
    }

    function bindProduct() {
        var id = config.product && config.product.id;
        if (!id || productListeners[id]) { return; }
        productListeners[id] = true;
        win.addEventListener('update-prices-' + id, function (event) {
            if (config.product.id === id) { prices(event.detail); }
        });
    }

    function updateContext(detail) {
        if (!config) { return; }
        detail = detail || {};
        if (typeof detail.scope === 'string' && ['product', 'cart', 'checkout', 'pages'].includes(detail.scope)) {
            config.page.scope = detail.scope;
            config.page.path = '/' + String(detail.path || win.location.pathname).replace(/^\/+|\/+$/g, '');
            productPrice = null;
            cartValue = null;
            if (Number.isInteger(detail.productId) && detail.productId > 0) { config.product.id = detail.productId; bindProduct(); }
            requestRefresh();
        }
        if (Object.prototype.hasOwnProperty.call(detail, 'productPrice')) {
            productPrice = convert(detail.productPrice, detail.currency);
        }
        if (Object.prototype.hasOwnProperty.call(detail, 'cartValue')) {
            cartValue = convert(detail.cartValue, detail.currency);
        }
        debug('storefront context changed');
        schedule();
    }

    function readConfig() {
        var roots = doc.querySelectorAll('[data-le-personalizations-config]');
        var root = roots[roots.length - 1];
        // A headless client may retain only the bridge after its first mount.
        if (!root) { return !!config; }
        var encoded = root.getAttribute('data-le-personalizations-config');
        if (encoded === encodedConfig) { return !!config; }
        var next;
        try { next = JSON.parse(encoded); } catch (error) { return false; }
        if (!next || !uuid.test(next.tenant || '') || !Array.isArray(next.rules)) { return false; }
        if (config && config.tenant !== next.tenant.toLowerCase()) {
            debug('tenant changed: full page navigation required');
            return false;
        }
        encodedConfig = encoded;
        identityChanged(undefined);
        config = next;
        config.tenant = config.tenant.toLowerCase();
        rules = Object.create(null);
        config.rules.forEach(function (rule) { if (rule.enabled) { rules[rule.key] = rule; } });
        ready = false;
        loading = false;
        ++generation;
        variantSelected = false;
        currentPrices = null;
        cartValue = null;
        capabilities = {personalized: false, cart: false};
        productPrice = convert(config.product.price, config.displayCurrency);
        bindProduct();
        return Object.keys(rules).length > 0;
    }

    var magewireBound = false;
    function bindMagewire() {
        if (magewireBound || !win.Magewire || typeof win.Magewire.hook !== 'function') { return; }
        win.Magewire.hook('message.processed', function () {
            if (config && config.page.scope === 'checkout') { requestRefresh(); }
        });
        magewireBound = true;
    }

    function start() {
        if (started) { return; }
        started = true;
        if (!readConfig()) { config = null; return; }
        win.addEventListener('loyaltyengage:storefront-context', function (event) { updateContext(event.detail); });
        win.addEventListener('magewire:load', bindMagewire);
        bindMagewire();
        win.addEventListener('configurable-selection-changed', function (event) {
            var detail = event.detail || {};
            if (String(detail.productId) !== String(config.product.id)) { return; }
            variantSelected = Array.isArray(detail.candidates) && detail.candidates.length === 1;
            if (currentPrices) { prices(currentPrices); }
        });
        win.addEventListener('update-product-final-price', function (event) {
            if (config.page.scope !== 'product' || config.product.type === 'configurable' && !variantSelected) { return; }
            var matchingTax = config.includingTax ? config.displayTaxMode === 2 : config.displayTaxMode === 1;
            productPrice = matchingTax ? convert(event.detail, config.displayCurrency) : null;
            schedule();
        });
        ['private-content-loaded', 'reload-customer-section-data', 'checkout:totals:updated'].forEach(function (name) {
            win.addEventListener(name, requestRefresh);
        });
        win.addEventListener('popstate', function () { updateContext({scope: 'pages', path: win.location.pathname}); });
        win.addEventListener('pageshow', requestRefresh);
        doc.addEventListener('visibilitychange', function () { if (!doc.hidden) { requestRefresh(); } });
        if (win.MutationObserver) {
            new win.MutationObserver(schedule).observe(doc.body, {subtree: true, childList: true,
                attributes: true, attributeFilter: ['class', 'style', 'hidden', 'data-le-personalization-key']});
        }
        if (typeof win.require === 'function' && win.require.config) {
            win.require(['jquery', 'Magento_Customer/js/customer-data'], function ($, customerData) {
                customerData.get('cart').subscribe(requestRefresh);
                customerData.get('customer').subscribe(requestRefresh);
                $(doc).on('priceUpdated.lePersonalizations', '[data-role="priceBox"]', function (event, data) {
                    var productId = this.getAttribute('data-product-id');
                    if (String(config.product.id) === productId) { prices(data); }
                });
                var box = $('[data-role="priceBox"][data-product-id="' + config.product.id + '"]');
                var instance = box.data('mage-priceBox');
                if (instance && instance.cache) { prices(instance.cache.displayPrices); }
            });
            if (config.page.scope === 'checkout') {
                win.require(['Magento_Checkout/js/model/quote'], function (quote) {
                    quote.totals.subscribe(requestRefresh);
                });
            }
        }
        doc.addEventListener('securitypolicyviolation', function (event) {
            if (String(event.blockedURI).startsWith('https://le-cloud.app')) { debug('loader blocked by CSP'); }
        });
        if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', scan, {once: true}); }
        else { scan(); }
    }

    return {start: start, scan: scan, refresh: requestRefresh, updateContext: updateContext};
}));
