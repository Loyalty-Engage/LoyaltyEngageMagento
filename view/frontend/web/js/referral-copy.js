(function () {
    'use strict';

    document.addEventListener('click', async function (event) {
        var button = event.target.closest && event.target.closest('[data-loyalty-referral-copy]'),
            section = button && button.closest('[data-loyalty-referral]'),
            input = section && section.querySelector('[data-loyalty-referral-url]'),
            status = section && section.querySelector('[data-loyalty-referral-status]');

        if (!input || !status) {
            return;
        }
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = status.dataset.copied;
        } catch (error) {
            input.focus();
            input.select();
            status.textContent = status.dataset.manual;
        }
    });
}());
