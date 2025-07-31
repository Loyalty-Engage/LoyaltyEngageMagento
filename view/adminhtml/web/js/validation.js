require([
    'jquery',
    'mage/validation'
], function ($) {
    'use strict';

    $.validator.addMethod(
        'validate-hex-color',
        function (value, element) {
            if (value === '') {
                return true; // Allow empty values
            }
            // Validate hex color format (#RRGGBB or #RGB)
            return /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(value);
        },
        $.mage.__('Please enter a valid hex color code (e.g., #28a745 or #fff)')
    );
});
