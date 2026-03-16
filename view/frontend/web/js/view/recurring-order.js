/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'ko',
    'uiComponent',
    'Qliro_QliroOne/js/model/config',
    'Qliro_QliroOne/js/action/set-recurring',
    'Qliro_QliroOne/js/model/qliro'
], function (
    ko,
    Component,
    config,
    setRecurringAction,
    qliroModel
) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Qliro_QliroOne/checkout/recurring-order'
        },

        availableFrequencyOptions: [],
        isRecurring: ko.observable(config.recurringOrder?.isRecurring || false),
        selectedFrequencyOption: ko.observable(''),

        initialize: function () {
            this._super();
            // Use config model to get recurring config
            const frequencyOptions = config.recurringOrder?.availableFrequencyOptions || [];
            if (frequencyOptions.length < 1) {
                this.template = '';
                return this;
            }
            this.availableFrequencyOptions = frequencyOptions;
            return this;
        },

        onRecurringConfigChange: function () {
            setRecurringAction({
                isRecurring:this.isRecurring(),
                frequencyOption: this.selectedFrequencyOption()
            }).done(function () {
                qliroModel.updateCart();
            });
        },
    });
});
