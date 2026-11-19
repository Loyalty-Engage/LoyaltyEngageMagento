var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/action/place-order': {
                'LoyaltyEngage_LoyaltyShop/js/enrollment-payment-mixin': true
            },
            'Magento_Checkout/js/action/set-payment-information-extended': {
                'LoyaltyEngage_LoyaltyShop/js/enrollment-payment-mixin': true
            }
        }
    }
};
