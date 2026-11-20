const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync(require('node:path').join(__dirname, '../../view/frontend/web/js/referral-copy.js'), 'utf8');

function fixture(clipboard) {
    let click;
    const input = {
        value: 'https://example.com/invite/test-00042',
        focus() { this.focused = true; },
        select() { this.selected = true; }
    };
    const status = {dataset: {copied: 'Copied', manual: 'Copy selected text'}};
    const button = {closest: () => ({
        querySelector: selector => selector === '[data-loyalty-referral-url]' ? input : status
    })};
    vm.runInNewContext(script, {
        document: {addEventListener: (name, callback) => { assert.equal(name, 'click'); click = callback; }},
        navigator: {clipboard}
    });
    return {input, status, click: () => click({target: {closest: () => button}})};
}

test('copies the generated URL and announces success', async () => {
    let copied;
    const s = fixture({writeText: async value => { copied = value; }});
    await s.click();
    assert.equal(copied, s.input.value);
    assert.equal(s.status.textContent, 'Copied');
});

test('selects the URL if clipboard access is unavailable', async () => {
    const s = fixture(undefined);
    await s.click();
    assert.equal(s.input.focused, true);
    assert.equal(s.input.selected, true);
    assert.equal(s.status.textContent, 'Copy selected text');
});
