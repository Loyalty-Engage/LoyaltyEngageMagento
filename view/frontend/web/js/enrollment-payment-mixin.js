define(['jquery'], function ($) {
    'use strict';

    return function (action) {
        return function () {
            var context = this,
                args = arguments;

            if (!window.loyaltyEnrollmentCheckout || !document.querySelector('[data-loyalty-enrollment]')) {
                return action.apply(context, args);
            }

            return $.Deferred(function (deferred) {
                window.loyaltyEnrollmentCheckout.save().then(function (saved) {
                    if (!saved) {
                        deferred.reject();
                        return;
                    }
                    $.when(action.apply(context, args))
                        .done(function () { deferred.resolveWith(this, arguments); })
                        .fail(function () { deferred.rejectWith(this, arguments); });
                }).catch(function () { deferred.reject(); });
            }).promise();
        };
    };
});
