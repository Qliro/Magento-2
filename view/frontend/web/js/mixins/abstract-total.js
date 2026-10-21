/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Core shows the summary totals only after the shipping step, which the QliroOne checkout page
 * never passes, so the buyer saw the products and nothing else.
 */

define([
    'Qliro_QliroOne/js/model/config'
], function (config) {
    'use strict';

    return function (abstractTotal) {
        if (!config.enabled || !config.isQliroCheckoutPage) {
            return abstractTotal;
        }

        return abstractTotal.extend({
            isFullMode: function () {
                return !!this.getTotals();
            }
        });
    };
});
