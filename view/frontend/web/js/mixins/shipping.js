/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Remove template rendering for this component
 *
 * The checkout page empties the same component through a layout processor, so this is the fallback
 * for a theme or an extension that renders the shipping step from a layout of its own.
 */

define([
    'Qliro_QliroOne/js/model/config'
], function (config) {
    'use strict';

    return function (shippingFunction) {
        var result = {};

        // The page flag comes from the server, so another host or a rewritten URL does not bring the step back
        if (config.enabled && config.hideNativeShippingStep && config.isQliroCheckoutPage) {
            result = {defaults: {template: ''}};
        }
        return shippingFunction.extend(result);
    }
});
