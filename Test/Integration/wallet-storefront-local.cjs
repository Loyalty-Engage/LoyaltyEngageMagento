'use strict';

// Requires wallet-storefront-fixture.php enable; always restore afterwards.
// This test never places an order or disables TLS verification.
const https = require('node:https');
const fs = require('node:fs');
const assert = require('node:assert/strict');

assert.equal(process.env.LOYALTY_WALLET_LOCAL_TEST, '1', 'Local opt-in required');
assert.ok(process.env.LOCAL_WALLET_TEST_PASSWORD, 'Fictional test account password required');
assert.ok(process.env.LOCAL_WALLET_TEST_CA, 'Local certificate PEM required');
const ca = fs.readFileSync(process.env.LOCAL_WALLET_TEST_CA);

class Client {
    constructor(store) {
        this.store = store;
        this.cookies = new Map([['store', store]]);
    }

    async request(path, fields, redirects = 0) {
        const url = new URL(path, 'https://magento.test');
        assert.equal(url.origin, 'https://magento.test');
        if (!/^\/(default|hyva)(\/|$)/.test(url.pathname)) {
            url.pathname = '/' + this.store + url.pathname;
        }
        const body = fields ? new URLSearchParams(fields).toString() : null;
        const response = await new Promise((resolve, reject) => {
            const request = https.request(url, {
                ca,
                method: body ? 'POST' : 'GET',
                headers: {
                    Cookie: [...this.cookies].map(([key, value]) => `${key}=${value}`).join('; '),
                    'User-Agent': 'LoyaltyWalletLocalAcceptance',
                    Origin: url.origin,
                    Referer: url.origin + '/',
                    ...(body ? {'Content-Type': 'application/x-www-form-urlencoded',
                        'Content-Length': Buffer.byteLength(body)} : {}),
                },
            }, result => {
                const chunks = [];
                result.on('data', chunk => chunks.push(chunk));
                result.on('end', () => resolve({status: result.statusCode, headers: result.headers,
                    text: Buffer.concat(chunks).toString()}));
            });
            request.setTimeout(90000, () => request.destroy(new Error('Request timeout')));
            request.on('error', reject);
            request.end(body);
        });
        for (const cookie of response.headers['set-cookie'] || []) {
            const pair = cookie.split(';')[0];
            const index = pair.indexOf('=');
            const key = pair.slice(0, index);
            const value = pair.slice(index + 1);
            if (/max-age=0(?:;|$)/i.test(cookie) || value === 'deleted') {
                this.cookies.delete(key);
            } else {
                this.cookies.set(key, value);
            }
        }
        if ([301, 302, 303].includes(response.status) && response.headers.location) {
            assert.ok(redirects < 8, 'Too many redirects');
            return this.request(response.headers.location, undefined, redirects + 1);
        }
        assert.equal(response.status, 200, 'Expected successful local page: ' + url.pathname);
        return response;
    }

    key(html) {
        const input = html.match(/<input\b[^>]*\bname=["']form_key["'][^>]*>/i)?.[0];
        const key = input?.match(/\bvalue=["']([^"']+)["']/i)?.[1];
        assert.ok(key, 'CSRF form key required');
        this.cookies.set('form_key', key);
        return key;
    }
}

function grandTotal(html) {
    const decoded = html.replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
        .replace(/&#([0-9]+);/g, (_, n) => String.fromCodePoint(Number(n)))
        .replace(/&quot;/g, '"').replace(/\\"/g, '"');
    const value = decoded.match(/"(?:base_)?grand_total"\s*:\s*"?(-?\d+(?:\.\d+)?)/)?.[1];
    assert.ok(value !== undefined, 'Native grand total required');
    return Math.round(Number(value) * 100);
}

(async () => {
    const coins = process.env.LOCAL_WALLET_TEST_COINS === '1';
    for (const store of ['default', 'hyva']) {
        const client = new Client(store);
        let page = await client.request('/customer/account/login/');
        await client.request('/customer/account/loginPost/', {form_key: client.key(page.text),
            'login[username]': 'personalization-demo@example.com',
            'login[password]': process.env.LOCAL_WALLET_TEST_PASSWORD});
        page = await client.request('/checkout/cart/');
        if (!/name=["']cart\[\d+\]\[qty\]["']/.test(page.text)) {
            const product = await client.request('/loyalty-demo-product.html');
            await client.request('/checkout/cart/add/product/1/', {
                form_key: client.key(product.text), product: '1', qty: '1',
            });
            page = await client.request('/checkout/cart/');
        }
        const ids = [...page.text.matchAll(/name=["']cart\[(\d+)\]\[qty\]["']/g)].map(match => match[1]);
        assert.equal(new Set(ids).size, 1, 'Exactly one ordinary fictional demo item required');
        await client.request('/checkout/cart/updatePost/', {form_key: client.key(page.text),
            [`cart[${ids[0]}][qty]`]: '1', update_cart_action: 'update_qty'});
        page = await client.request('/checkout/cart/');
        assert.match(page.text, /id="loyalty-wallet-amount"/);
        if (coins) {
            assert.match(page.text, /step="1"/);
            assert.match(page.text, /Loyalty coins are spent/);
        }
        const apply = async (amount, key) => {
            await client.request('/loyalty/wallet/apply/', {form_key: key || client.key(page.text),
                amount, return_to: 'cart'});
            page = await client.request('/checkout/cart/');
            return grandTotal(page.text);
        };
        await apply('0');
        const original = grandTotal(page.text);
        assert.ok(original > 1000 && original < 10000);
        assert.equal(await apply('10.00'), original - 1000);
        if (coins) { assert.equal(await apply('10.99'), original - 1000); }
        assert.match(page.text, /loyalty_wallet/);
        assert.equal(await apply('15.00', 'invalid-csrf'), original - 1000);
        assert.equal(await apply('-1.00'), original - 1000);
        let account = await client.request('/loyalty/wallet/index/');
        assert.match(account.text, /Available credit: EUR 100\.00/);
        assert.match(account.headers['cache-control'], /no-store/);
        assert.equal(await apply('0'), original);
        assert.equal(await apply('100.00'), coins ? original % 100 : 0);
        const checkout = await client.request('/checkout/');
        assert.match(checkout.text, /loyalty-wallet-amount/);
        if (store === 'hyva') { assert.match(checkout.text, /hyvaCheckout|magewire/); }
        assert.equal(await apply('0'), original);
        account = await client.request('/loyaltyshop/wallet/index/');
        assert.match(account.text, /Available credit: EUR 100\.00/);
        await client.request('/customer/account/logout/');
        const guest = await client.request('/loyalty/wallet/index/');
        assert.match(guest.text, /name=["']login\[username\]["']/);
        assert.doesNotMatch(guest.text, /Available credit: EUR 100\.00/);
        console.log(JSON.stringify({store, coins, partialAndFull: true, remove: true, csrf: true,
            negativeInput: true, checkout: true, privateAccount: true, logout: true, noOrderPlaced: true}));
    }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
