'use strict';
const assert = require('node:assert/strict');
const {test} = require('node:test');
const create = require('../../view/frontend/web/js/personalizations.js');
const tenant = '12345678-1234-4234-8234-123456789abc';
const hash = 'a'.repeat(64);

function rule(key, extra = {}) {
    return {key, public_id: 'bp-12', enabled: 1, placement: 'manual', page_scope: 'product', pages: '',
        personalized: 0, use_cart_value: 0, ...extra};
}

function fixture(rows, options = {}) {
    const events = {}, domEvents = {}, frames = [], timers = new Map(), scripts = [], logs = [], calls = [];
    let timerId = 0, observer;
    const settings = {tenant, rules: rows, debug: true, contextUrl: '/loyalty/personalization/context',
        currency: 'EUR', displayCurrency: 'EUR', displayRate: 1, includingTax: false,
        page: {scope: 'product', path: '/product.html'}, product: {id: 10, type: 'simple', price: 25}, ...options.config};
    const nodes = rows.map((row, index) => node(row.key, 'le-target-' + index));
    function node(key, id = '') {
        return {id, isConnected: true, visible: true, children: [],
            getAttribute: name => name === 'data-le-personalization-key' ? key : null,
            getClientRects() { return this.visible ? [{}] : []; },
            replaceChildren() { this.children = []; }};
    }
    const doc = {readyState: 'complete', hidden: false, body: {},
        head: {appendChild: script => scripts.push(script)},
        querySelector: selector => selector.includes('data-le-personalizations-config')
            ? {getAttribute: () => JSON.stringify(settings)} : scripts.find(s => selector === 'script[src="' + s.src + '"]'),
        querySelectorAll: selector => selector.includes('data-le-personalizations-config')
            ? [{getAttribute: () => JSON.stringify(settings)}] : nodes.filter(n => n.isConnected),
        getElementById: id => nodes.find(n => n.isConnected && n.id === id),
        createElement: () => ({addEventListener(name, fn) { this[name] = fn; }}),
        addEventListener: (name, fn) => { domEvents[name] = fn; }};
    const win = {location: {href: 'https://magento.test/product.html', origin: 'https://magento.test', pathname: '/product.html'},
        console: {debug: (...args) => logs.push(args)},
        setTimeout: (fn, delay = 0) => { const id = ++timerId; timers.set(id, {fn, delay}); return id; },
        clearTimeout: id => timers.delete(id), requestAnimationFrame: fn => frames.push(fn),
        getComputedStyle: () => ({visibility: 'visible'}),
        addEventListener: (name, fn) => { (events[name] ||= []).push(fn); },
        MutationObserver: class { constructor(fn) { observer = fn; } observe() {} },
        fetch: async (url, init) => {
            calls.push({url, init});
            if (options.fail) { throw new Error('network failure with sensitive text'); }
            return {ok: true, headers: {get: () => 'application/json'}, json: async () => options.context || {}};
        }};
    const app = create(win, doc);
    async function flush() {
        for (let i = 0; i < 12; ++i) {
            const current = frames.splice(0);
            current.forEach(fn => fn());
            for (const [id, timer] of timers) {
                if (timer.delay <= 100) { timers.delete(id); timer.fn(); }
            }
            await Promise.resolve();
        }
    }
    return {app, nodes, scripts, logs, calls, win, options, settings, flush,
        start: async () => { app.start(); await flush(); },
        push: () => win._lecloud?.personalization || [],
        emit: async (name, detail) => { (events[name] || []).forEach(fn => fn({detail})); await flush(); },
        mount: key => { const n = node(key); nodes.push(n); observer?.(); return n; }};
}

test('no active rules or invalid tenant: no loader, requests or triggers', async () => {
    for (const options of [{}, {config: {tenant: 'https://evil.test/code.js'}}]) {
        const f = fixture([rule('disabled', {enabled: 0})], options);
        await f.start();
        assert.equal(f.scripts.length, 0);
        assert.equal(f.calls.length, 0);
        assert.equal(f.push().length, 0);
    }
});

test('multiple targets share one module loader; repeat scans are deduplicated', async () => {
    const f = fixture([rule('one'), rule('two')]);
    await f.start();
    assert.equal(f.scripts.length, 1);
    assert.equal(f.scripts[0].type, 'module');
    assert.equal(f.scripts[0].async, true);
    assert.equal(f.scripts[0].src, `https://le-cloud.app/f/t/${tenant}.js`);
    assert.equal(f.push().length, 2);
    assert.notEqual(f.push()[0].target, f.push()[1].target);
    f.app.scan();
    assert.equal(f.push().length, 2);
});

test('missing and hidden targets never load the loader or make context requests', async () => {
    const f = fixture([rule('hidden', {personalized: 1})]);
    f.nodes[0].visible = false;
    await f.start();
    assert.equal(f.scripts.length, 0);
    assert.equal(f.calls.length, 0);
    f.nodes[0].isConnected = false;
    f.app.scan();
    assert.equal(f.push().length, 0);
});

test('every automatic scope matches only its page and selected paths', async () => {
    for (const scope of ['product', 'cart', 'checkout', 'pages']) {
        const f = fixture(['product', 'cart', 'checkout', 'pages'].map(s => rule(s,
            {placement: 'automatic', page_scope: s, pages: '/membership'})),
        {config: {page: {scope, path: '/membership'}}});
        await f.start();
        assert.equal(f.push().length, 1);
        assert.equal(f.push()[0].target, '#le-target-' + ['product', 'cart', 'checkout', 'pages'].indexOf(scope));
    }
    const f = fixture([rule('other', {placement: 'automatic', page_scope: 'pages', pages: '/other'})]);
    await f.start();
    assert.equal(f.push().length, 0);
});

test('guests and non-personalized rules never send identifier; session hash is private', async () => {
    const guest = fixture([rule('personal', {personalized: 1})]);
    await guest.start();
    assert.deepEqual(Object.keys(guest.push()[0]).sort(), ['id', 'target']);
    const f = fixture([rule('static'), rule('personal', {personalized: 1})], {context: {identifier: hash}});
    await f.start();
    assert.equal(f.push().find(p => p.target === '#le-target-0').identifier, undefined);
    assert.equal(f.push().find(p => p.identifier).identifier, hash);
    assert.equal(f.calls[0].init.credentials, 'same-origin');
    assert.equal(f.calls[0].init.cache, 'no-store');
    assert.ok(!JSON.stringify(f.logs).includes(hash));
});

test('storefront events cannot inject a customer identifier', async () => {
    const f = fixture([rule('personal', {personalized: 1})]);
    await f.start();
    await f.emit('loyaltyengage:storefront-context', {identifier: hash, productPrice: 12, currency: 'EUR'});
    assert.ok(f.push().every(p => !Object.hasOwn(p, 'identifier')));
});

test('logout clears personalized content and invalidates its old request selector', async () => {
    const f = fixture([rule('personal', {personalized: 1})], {context: {identifier: hash}});
    await f.start();
    const oldTarget = f.push().at(-1).target;
    f.nodes[0].children = ['private content'];
    f.options.context = {};
    await f.emit('private-content-loaded', {data: {customer: {}}});
    assert.equal(f.push().at(-1).identifier, undefined);
    assert.notEqual(f.push().at(-1).target, oldTarget);
    assert.deepEqual(f.nodes[0].children, []);
});

test('product and variant changes convert currency and deduplicate equal values', async () => {
    const f = fixture([rule('value', {use_cart_value: 1})],
        {config: {displayCurrency: 'USD', displayRate: 0.8}});
    await f.start();
    assert.equal(f.push()[0].cartValue, 20);
    await f.emit('update-prices-10', {basePrice: {amount: 30}, finalPrice: {amount: 36.3}});
    assert.equal(f.push().at(-1).cartValue, 24);
    const count = f.push().length;
    await f.emit('update-prices-10', {basePrice: {amount: 30}});
    assert.equal(f.push().length, count);
    await f.emit('update-prices-99', {basePrice: {amount: 100}});
    assert.equal(f.push().length, count);
});

test('inclusive mode uses finalPrice; invalid currency and amounts are omitted', async () => {
    const f = fixture([rule('value', {use_cart_value: 1})], {config: {includingTax: true}});
    await f.start();
    await f.emit('update-prices-10', {basePrice: {amount: 10}, finalPrice: {amount: 12.1}});
    assert.equal(f.push().at(-1).cartValue, 12.1);
    for (const value of [-1, NaN, Infinity, '12']) {
        await f.emit('loyaltyengage:storefront-context', {productPrice: value, currency: 'EUR'});
        assert.equal(f.push().at(-1).cartValue, undefined);
    }
    const missing = fixture([rule('value', {use_cart_value: 1})], {config: {currency: ''}});
    await missing.start();
    assert.equal(missing.push()[0].cartValue, undefined);
});

test('cart and checkout wait for current server context and retrigger only on changes', async () => {
    for (const scope of ['cart', 'checkout']) {
        const f = fixture([rule('cart', {use_cart_value: 1})],
            {config: {page: {scope, path: '/checkout'}}, context: {cartValue: 68}});
        await f.start();
        assert.equal(f.push().length, 1);
        assert.equal(f.push()[0].cartValue, 68);
        await f.emit('private-content-loaded', {});
        assert.equal(f.push().length, 1);
        f.options.context = {cartValue: 34};
        await f.emit('checkout:totals:updated', {});
        assert.equal(f.push().length, 2);
        assert.equal(f.push().at(-1).cartValue, 34);
    }
});

test('remounted components and duplicate HTML IDs get fresh unique targets', async () => {
    const f = fixture([rule('manual')]);
    await f.start();
    f.nodes[0].isConnected = false;
    const mount = f.mount('manual');
    mount.id = 'le-target-0';
    await f.flush();
    assert.equal(f.push().length, 2);
    const duplicate = f.mount('manual');
    duplicate.id = mount.id;
    await f.flush();
    assert.equal(f.push().length, 3);
    assert.notEqual(f.push().at(-1).target, f.push().at(-2).target);
    assert.equal(f.scripts.length, 1);
});

test('route context updates enforce scope and use a new mounted target', async () => {
    const f = fixture([rule('route', {placement: 'automatic', page_scope: 'pages', pages: '/membership'})]);
    await f.start();
    assert.equal(f.push().length, 0);
    await f.emit('loyaltyengage:storefront-context', {scope: 'pages', path: '/membership'});
    assert.equal(f.push().length, 1);
});

test('context failure, loader error and CSP diagnostics do not break the page or leak errors', async () => {
    const f = fixture([rule('personal', {personalized: 1})], {fail: true});
    await f.start();
    assert.equal(f.push().length, 1);
    assert.equal(f.push()[0].identifier, undefined);
    f.scripts[0].error();
    assert.ok(JSON.stringify(f.logs).includes('loader failed'));
    assert.ok(!JSON.stringify(f.logs).includes('sensitive text'));
});

test('a synchronously blocked fetch does not break rendering or expose errors', async () => {
    const f = fixture([rule('personal', {personalized: 1})]);
    f.win.fetch = () => { throw new Error('private diagnostic must not leak'); };
    await f.start();
    assert.equal(f.push().length, 1);
    assert.equal(f.push()[0].identifier, undefined);
    assert.ok(!JSON.stringify(f.logs).includes('private diagnostic'));
});

test('Hyva Checkout refreshes private totals after Magewire updates and binds only once', async () => {
    const f = fixture([rule('checkout', {use_cart_value: 1})],
        {config: {page: {scope: 'checkout', path: '/checkout'}}, context: {cartValue: 10}});
    const hooks = [];
    f.win.Magewire = {hook: (name, callback) => hooks.push({name, callback})};
    await f.start();
    await f.emit('magewire:load');
    assert.equal(hooks.length, 1);
    assert.equal(hooks[0].name, 'message.processed');
    assert.equal(f.push().at(-1).cartValue, 10);
    f.options.context = {cartValue: 20};
    hooks[0].callback();
    await f.flush();
    assert.equal(f.push().at(-1).cartValue, 20);
    assert.equal(f.calls.length, 2);
});

test('a later personalized mount fetches identity even when cart context was already ready', async () => {
    const f = fixture([rule('cart', {use_cart_value: 1}), rule('late', {personalized: 1})],
        {config: {page: {scope: 'cart', path: '/checkout/cart'}}, context: {cartValue: 10, identifier: hash}});
    f.nodes[1].visible = false;
    await f.start();
    f.nodes[1].visible = true;
    f.app.scan();
    await f.flush();
    assert.equal(f.push().filter(p => p.identifier === hash).length, 1);
    assert.equal(f.calls.length, 2);
});

test('configurable price is omitted until a single selected variant is known', async () => {
    const f = fixture([rule('variant', {use_cart_value: 1})],
        {config: {product: {id: 10, type: 'configurable', price: null}}});
    await f.start();
    await f.emit('update-prices-10', {basePrice: {amount: 17.5}});
    assert.equal(f.push().at(-1).cartValue, undefined);
    await f.emit('configurable-selection-changed', {productId: '10', candidates: ['12']});
    assert.equal(f.push().at(-1).cartValue, 17.5);
    await f.emit('configurable-selection-changed', {productId: '10', candidates: ['12', '13']});
    assert.equal(f.push().at(-1).cartValue, undefined);
});

test('new server-rendered route configuration replaces the old page context', async () => {
    const f = fixture([rule('route', {placement: 'automatic', page_scope: 'pages', pages: '/membership'})]);
    await f.start();
    f.settings.page = {scope: 'pages', path: '/membership'};
    f.app.scan();
    await f.flush();
    assert.equal(f.push().length, 1);
});

test('missing currency is omitted for cart values too', async () => {
    const f = fixture([rule('cart', {use_cart_value: 1})],
        {config: {currency: '', page: {scope: 'cart', path: '/checkout/cart'}}, context: {cartValue: 0}});
    await f.start();
    assert.equal(f.push()[0].cartValue, undefined);
});
