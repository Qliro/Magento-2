define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/shipping-rate-registry'
], function (quote, rateRegistry) {
    'use strict';

    return function (originalAction) {
        return function () {
            if (!quote.isVirtual()) {
                var shippingAddress = quote.shippingAddress();

                if (shippingAddress && typeof shippingAddress.getCacheKey === 'function') {
                    rateRegistry.set(shippingAddress.getCacheKey(), null);
                }
            }
        };
    };
});
