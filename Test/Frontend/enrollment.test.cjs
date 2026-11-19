const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(require('node:path').join(__dirname, '../../view/frontend/web/js/enrollment.js'), 'utf8');

function load(fetch, {present = true} = {}) {
    const choice = {checked: false, disabled: false};
    const error = {hidden: true};
    const block = {
        dataset: {url: '/save', formKey: 'form', quoteToken: 'quote'},
        querySelector: name => name === '[data-loyalty-choice]' ? choice : error
    };
    const hooks = {};
    const window = {addEventListener: (name, fn) => { hooks[name] = fn; }};
    const context = vm.createContext({window, document: {querySelector: () => present ? block : null}, fetch, URLSearchParams});
    vm.runInContext(script, context);
    return {choice, error, window, hooks, context};
}

test('only the checkout submit/validation saves the current checkbox state', async () => {
    const values = [];
    const fixture = load(async (url, request) => {
        assert.equal(url, '/save');
        assert.equal(request.body.get('quote_token'), 'quote');
        values.push(request.body.get('subscribe'));
        return {ok: true, json: async () => ({success: true})};
    });
    fixture.choice.checked = true;
    assert.deepEqual(values, []);
    let validate;
    fixture.context.testHyva = {
        navigation: {hasCurrent: () => true},
        validation: {register: (name, fn) => { validate = fn; }}
    };
    vm.runInContext('const hyvaCheckout = testHyva;', fixture.context);
    fixture.hooks['checkout:step:loaded']();
    assert.equal(await validate(), true);
    fixture.choice.checked = false;
    assert.equal(await validate(), true);
    assert.deepEqual(values, ['1', '0']);
});

test('failed consent persistence blocks order and exposes a visible message', async () => {
    const fixture = load(async () => ({ok: false}));
    assert.equal(await fixture.window.loyaltyEnrollmentCheckout.save(), false);
    assert.equal(fixture.error.hidden, false);
    assert.equal(fixture.choice.disabled, false);
});

test('disabled enrollment does not issue a request or block checkout', async () => {
    const fixture = load(() => assert.fail('unexpected request'), {present: false});
    assert.equal(await fixture.window.loyaltyEnrollmentCheckout.save(), true);
});
