define(['uiComponent', 'mage/translate'], function (Component, $t) {
    'use strict';

    return Component.extend({
        defaults: {template: 'LoyaltyEngage_LoyaltyShop/enrollment'},
        settings: window.checkoutConfig.loyaltyEnrollment || {},
        advisoryText: $t('You participate in the loyalty program regardless of this choice.'),
        errorText: $t('Your loyalty preference could not be saved. Refresh checkout and try again.')
    });
});
