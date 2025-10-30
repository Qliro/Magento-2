/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

define(['jquery'], function ($) {
    'use strict';

    function initListener() {
        if (typeof window.q1 !== 'undefined' && typeof window.q1.onSessionExpired === 'function') {
            window.q1.onSessionExpired(function updateToken() {
                $.ajax({
                    url: window.BASE_URL + 'checkout/link/expire',
                    type: 'POST'
                }).always(function () {
                    window.location.reload();
                });
            });
        } else {
            setTimeout(initListener, 250);
        }
    }

    initListener();
});
