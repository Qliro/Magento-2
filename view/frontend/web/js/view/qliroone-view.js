/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

define([
    'ko',
    'uiComponent',
    'underscore',
    'Magento_Checkout/js/model/step-navigator',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/quote',
    'Qliro_QliroOne/js/model/qliro',
    'Qliro_QliroOne/js/model/config'
], function (
    ko,
    Component,
    _,
    stepNavigator,
    shippingService,
    customerData,
    quote,
    qliro,
    config
) {
    'use strict';
    return Component.extend({
        defaults: {
            template: 'Qliro_QliroOne/checkout/onepage',
            imports: {
                discountApplied: 'checkout.steps.billing-step.discount:isApplied',
                isRecurring: 'checkout.steps.qliro-recurring-order:isRecurring'
            },
        },
        discountApplied: ko.observable(false),
        isRecurring: ko.observable(false),

        isVisible: ko.observable(true),

        initialize: function () {
            this._super();

            this.initializeSubscribers();
            this.initializeQliro();

            // if (!quote.isVirtual()) {
            //     stepNavigator.registerStep('shipping', null, 'Shipping', false, _.bind(this.navigate, this), 0);
            // }

            stepNavigator.registerStep(
                'qliroone-step',
                null,
                config.checkoutTitle,
                this.isVisible,
                _.bind(this.navigate, this),
                100
            );

            return this;
        },

        initializeSubscribers: function() {
            this.discountApplied.subscribe(function() {
                qliro.updateCart();
            });
            quote.shippingAddress.subscribe(function(address) {
                qliro.updateCart();
            });
        },

        initializeQliro: function() {
            qliro.registerCallbacks();
        },

        /**
         * The navigate() method is responsible for navigation between checkout step
         * during checkout. You can add custom logic, for example some conditions
         * for switching to your custom step. (This method is required even though it
         * is blank, don't delete)
         */
        navigate: function () {
        },

        navigateToNextStep: function () {
            stepNavigator.next();
        }
    });
});
