'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const template = fs.readFileSync(path.join(__dirname,
    '../../view/frontend/templates/account/loyalty-meta-bootstrap.phtml'), 'utf8');
const source = template.split('$script = <<<JS\n')[1].split('\nJS;')[0];

function bootstrap(initial, cached, luma = true) {
    const listeners = {};
    const actions = [];
    const body = { innerHTML: '' };
    let subscriber;
    const customer = () => cached;
    customer.subscribe = callback => { subscriber = callback; };
    const window = {
        hyva: { setCookie: (...args) => actions.push(['cookie', ...args]) },
        addEventListener: (name, fn) => { listeners[name] = fn; },
        dispatchEvent: event => {
            actions.push(['event', event.type]);
            if (listeners[event.type]) listeners[event.type](event);
        },
    };
    const context = {
        window,
        document: {
            querySelector: () => null,
            querySelectorAll: () => [{
                querySelector: () => body,
                getAttribute: () => '',
                removeAttribute: () => {},
            }],
        },
        CustomEvent: class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } },
    };
    if (luma) {
        context.require = (_deps, callback) => callback({
            get: () => customer,
            reload: (...args) => actions.push(['reload', ...args]),
        });
    }
    vm.runInNewContext(source
        .replace('{$metaJson}', JSON.stringify(initial))
        .replace('{$labelsJson}', JSON.stringify({ le_points: 'Points' })), context);
    return {
        window, actions, body,
        change: value => { cached = value; subscriber(); },
        hyva: value => listeners['private-content-loaded']({ detail: { data: { customer: value } } }),
    };
}

test('Luma retains fresh server data and reloads stale sections only once', () => {
    const app = bootstrap({ le_points: 200 }, { loyalty_meta: { le_points: 100 } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_points, 200);
    assert.equal(app.actions.filter(a => a[0] === 'reload').length, 1);
    app.change({ loyalty_meta: { le_points: 200 } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_points, 200);
    assert.equal(app.actions.filter(a => a[0] === 'reload').length, 1);
});

test('Equal server and browser values cause no extra request', () => {
    const app = bootstrap({ le_points: 100 }, { loyalty_meta: { le_points: '100' } });
    assert.equal(app.actions.filter(a => a[0] === 'reload').length, 0);
});

test('Cacheable pages use customer sections without a server-side customer snapshot', () => {
    const app = bootstrap({}, { loyalty_meta: { le_points: 100 } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_points, 100);
    assert.equal(app.actions.filter(a => a[0] === 'reload').length, 0);
});

test('Logout clears previously displayed private data', () => {
    const app = bootstrap({}, { loyalty_meta: { le_points: 100 } });
    app.change({});
    assert.equal(Object.keys(app.window.loyaltyEngageCustomerMeta).length, 0);
    assert.ok(!app.body.innerHTML.includes('100'));
});

test('Hyva invalidates its cache session before requesting new data', () => {
    const app = bootstrap({ le_points: 200 }, {}, false);
    app.hyva({ loyalty_meta: { le_points: 100 } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_points, 200);
    const cookie = app.actions.findIndex(a => a[0] === 'cookie');
    const reload = app.actions.findIndex(a => a[1] === 'reload-customer-section-data');
    assert.ok(cookie >= 0 && cookie < reload);
    assert.deepEqual(app.actions[cookie], ['cookie', 'mage-cache-sessid', '', -1, true]);
    app.hyva({ loyalty_meta: { le_points: 200 } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_points, 200);
    assert.equal(app.actions.filter(a => a[0] === 'cookie').length, 1);
});

test('Disabled fields are excluded and untrusted values are escaped', () => {
    const app = bootstrap({}, { loyalty_meta: { le_points: '<img src=x onerror=alert(1)>', le_hidden: 'secret' } });
    assert.equal(app.window.loyaltyEngageCustomerMeta.le_hidden, undefined);
    assert.ok(app.body.innerHTML.includes('&lt;img'));
    assert.ok(!app.body.innerHTML.includes('<img'));
    assert.ok(!app.body.innerHTML.includes('secret'));
});
