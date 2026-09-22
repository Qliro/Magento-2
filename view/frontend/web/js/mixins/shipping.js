/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Remove template rendering for this component
 *
 * The checkout page empties the same component through a layout processor, so this is the fallback
 * for a theme or an extension that renders the shipping step from a layout of its own.
 */

define([], function () {
    'use strict';

    /**
     * Tell whether the given URL addresses the page we are on, ignoring query, fragment and trailing slash
     *
     * @param {String} url
     * @return {Boolean}
     */
    function isCurrentPage(url) {
        var link = document.createElement('a'),
            trim = function (path) {
                return path.replace(/\/+$/, '');
            };

        link.href = url;

        return link.host === window.location.host && trim(link.pathname) === trim(window.location.pathname);
    }

    return function (shippingFunction) {
        var config = window.checkoutConfig && window.checkoutConfig.qliro,
            result = {};

        if (config && config.enabled && config.hideNativeShippingStep &&
            config.checkoutUrl && isCurrentPage(config.checkoutUrl)
        ) {
            result = {defaults: {template: ''}};
        }
        return shippingFunction.extend(result);
    }
});

