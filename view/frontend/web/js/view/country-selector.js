/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
    'ko',
    'uiComponent',
    'Qliro_QliroOne/js/model/config',
    'Qliro_QliroOne/js/action/select-country'
], function (
    ko,
    Component,
    config,
    selectCountryAction
) {
    'use strict';
    return Component.extend({
        defaults: {
            template: 'Qliro_QliroOne/checkout/country-selector'
        },

        availableCountries: [],
        selectedCountry: ko.observable(''),

        initialize: function () {
            this._super();
            // Use config model to get countries
            const countries = config.countrySelector?.availableCountries || [];
            if (countries.length < 1) {
                this.template = '';
                return this;
            }
            this.availableCountries = countries;
            this.selectedCountry = config.countrySelector.selectedCountry;
            return this;
        },

        onCountryChange: function () {
            selectCountryAction(this.selectedCountry);
        }
    });
});
