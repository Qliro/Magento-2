/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Qliro_QliroOne/js/model/config',
        'Qliro_QliroOne/js/model/qliro',
        'Magento_Checkout/js/model/quote'
    ],
    function ($, Component, config, qliro, quote) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Qliro_QliroOne/payment/qliroone'
            },

            initialize: function () {
                this._super();

                var self = this;
                this.selectedMethodSubscription = quote.paymentMethod.subscribe(function (method) {
                    if (self.iframeMounted && (!method || method.method !== self.getCode())) {
                        self.teardownIframe();
                    }
                });

                return this;
            },

            /** The DOM node the Qliro iframe snippet is mounted into. */
            iframeContainer: null,

            /** Raw OrderHtmlSnippet returned by the backend, pending mount. */
            snippetHtml: null,

            iframeLoading: false,
            iframeMounted: false,

            /**
             * The method renders inline as an embedded iframe (vs. redirecting to the
             * standalone Qliro checkout page).
             *
             * @returns {boolean}
             */
            isIframeMode: function () {
                return config.paymentMethodRenderMode === 'iframe';
            },

            /**
             * Captures the container element (bound via afterRender) so the snippet can be
             * mounted into it once fetched.
             *
             * @param {HTMLElement} element
             */
            setIframeContainer: function (element) {
                this.iframeContainer = element;
                this.renderSnippet();
            },

            /**
             * Select the Qliro payment method. In iframe mode, lazily load the embedded
             * checkout; in redirect mode keep the legacy button behaviour.
             *
             * @returns {boolean}
             */
            selectPaymentMethod: function () {
                this._super();

                if (this.isIframeMode()) {
                    this.loadSnippet();
                }

                return true;
            },

            /**
             * Fetch the Qliro order snippet on demand. The q1Ready handlers must be registered
             * before the snippet's bootstrap script runs, so they are wired up first.
             */
            loadSnippet: function () {
                if (this.iframeLoading || this.iframeMounted) {
                    return;
                }

                this.iframeLoading = true;
                this.registerQliroCallbacks();

                var self = this;

                $.ajax({
                    url: config.getSnippetUrl + '?token=' + config.securityToken,
                    method: 'POST'
                }).then(
                    function (data) {
                        self.iframeLoading = false;
                        self.snippetHtml = (data && data.snippet) || '';
                        self.renderSnippet();
                    },
                    function (response) {
                        var data = response.responseJSON || {};
                        self.iframeLoading = false;
                        self.messageContainer.addErrorMessage({
                            message: data.error || $.mage.__('Something went wrong while loading Qliro checkout.')
                        });
                    }
                );
            },

            renderSnippet: function () {
                if (this.iframeMounted || !this.iframeContainer || !this.snippetHtml) {
                    return;
                }

                this.iframeMounted = true;

                var container = this.iframeContainer,
                    fragment = document.createElement('div'),
                    nonce = this.getCspNonce();

                container.innerHTML = '';
                fragment.innerHTML = this.snippetHtml;

                Array.prototype.slice.call(fragment.childNodes).forEach(function (node) {
                    if (node.nodeName === 'SCRIPT') {
                        var script = document.createElement('script');

                        if (node.type) {
                            script.type = node.type;
                        }
                        // Magento's CSP blocks inline scripts that lack the page nonce. The
                        // server-rendered snippet gets one automatically; a script we build in JS
                        // does not, so copy the active page nonce onto it.
                        if (nonce) {
                            script.setAttribute('nonce', nonce);
                            script.nonce = nonce;
                        }
                        if (node.src) {
                            script.src = node.src;
                        } else {
                            script.text = node.textContent;
                        }
                        container.appendChild(script);
                    } else {
                        container.appendChild(node);
                    }
                });
            },

            getCspNonce: function () {
                var scripts = document.querySelectorAll('script'),
                    i;

                for (i = 0; i < scripts.length; i++) {
                    if (scripts[i].nonce) {
                        return scripts[i].nonce;
                    }
                }

                return '';
            },

            teardownIframe: function () {
                if (this.iframeContainer) {
                    this.iframeContainer.innerHTML = '';
                }

                window.q1Ready = null;
                window.q1 = null;

                this.iframeMounted = false;
                this.iframeLoading = false;
                this.snippetHtml = null;
            },

            registerQliroCallbacks: function () {
                window.q1Ready = function (q1) {
                    q1.onCheckoutLoaded(qliro.onCheckoutLoaded);
                    q1.onCustomerInfoChanged(qliro.onCustomerInfoChanged);
                    q1.onPaymentDeclined(qliro.onPaymentDeclined);
                    q1.onPaymentMethodChanged(qliro.onPaymentMethodChanged);
                    q1.onPaymentProcess(qliro.onPaymentProcessStart, qliro.onPaymentProcessEnd);
                    q1.onSessionExpired(qliro.onSessionExpired);
                    q1.onShippingMethodChanged(qliro.onShippingMethodChanged);
                    q1.onShippingPriceChanged(qliro.onShippingPriceChanged);
                };
            },

            redirectToQliroCheckout: function () {
                this.selectPaymentMethod();
                setTimeout(function () {
                    window.location = config.checkoutUrl;
                }, 1000);
            }
        });
    }
);
