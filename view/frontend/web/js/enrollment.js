(function () {
    'use strict';
    function save() {
        var block = document.querySelector('[data-loyalty-enrollment]'),
            choice = block && block.querySelector('[data-loyalty-choice]'),
            error = block && block.querySelector('[data-loyalty-error]');

        if (!choice) {
            return Promise.resolve(true);
        }
        if (error) {
            error.hidden = true;
        }
        choice.disabled = true;
        return fetch(block.dataset.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                form_key: block.dataset.formKey,
                quote_token: block.dataset.quoteToken,
                subscribe: choice.checked ? '1' : '0'
            })
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (response) {
            return Boolean(response && response.success === true);
        }).catch(function () {
            return false;
        }).then(function (saved) {
            choice.disabled = false;
            if (!saved && error) {
                error.hidden = false;
            }
            return saved;
        });
    }

    window.loyaltyEnrollmentCheckout = {save: save};

    function registerHyva() {
        var api = typeof hyvaCheckout !== 'undefined' ? hyvaCheckout : window.hyvaCheckout;
        if (api && api.validation && api.navigation
            && (typeof api.navigation.hasCurrent !== 'function' || api.navigation.hasCurrent())) {
            // Resolve the current field at validation time, including after a Magewire rerender.
            api.validation.register('loyaltyshop:enrollment', save);
        }
    }

    window.addEventListener('checkout:step:loaded', registerHyva);
    window.addEventListener('checkout:init:after', registerHyva);
}());
