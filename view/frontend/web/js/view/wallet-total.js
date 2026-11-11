define(['Magento_Checkout/js/view/summary/abstract-total', 'Magento_Checkout/js/model/totals'], function (Component, totals) {
    'use strict';
    return Component.extend({
        defaults: {template: 'LoyaltyEngage_LoyaltyShop/wallet-total'},
        segment: function () { return totals.getSegment('loyalty_wallet'); },
        isDisplayed: function () { var segment = this.segment(); return segment && Number(segment.value) < 0; },
        getTitle: function () { var segment = this.segment(); return segment ? segment.title : ''; },
        getValue: function () { var segment = this.segment(); return this.getFormattedPrice(segment ? segment.value : 0); }
    });
});
