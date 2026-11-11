(function () {
    'use strict';
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('.loyalty-enrollment input[name="subscribe"]')) {
            return;
        }
        // A normal POST/redirect finishes saving before checkout can continue.
        // The submit button also supports storefronts where scripts are disabled.
        if (input.form.requestSubmit) {
            input.form.requestSubmit();
        } else {
            input.form.submit();
        }
    });
}());
