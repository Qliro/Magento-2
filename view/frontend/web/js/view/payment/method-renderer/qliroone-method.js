/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
        'Magento_Checkout/js/view/payment/default'
    ],
    function (Component) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Qliro_QliroOne/payment/qliroone'
            },

            redirectToQliroCheckout: function () {
                this.selectPaymentMethod();
                setTimeout(function () {
                    window.location = window.checkoutConfig.qliro.checkoutUrl;
                }, 1000);
            }
        });
    }
);
