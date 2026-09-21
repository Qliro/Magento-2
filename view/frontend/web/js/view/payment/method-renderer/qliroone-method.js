/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

define([
        'jquery',
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/customer-data',
        'Qliro_QliroOne/js/model/config',
        'Qliro_QliroOne/js/model/qliro',
        'mage/translate'
    ],
    function ($, ko, Component, quote, customerData, config, qliro, $t) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Qliro_QliroOne/payment/qliroone'
            },

            /** The node the snippet is mounted into, captured by afterRender. */
            iframeContainer: null,

            /** The OrderHtmlSnippet the backend returned, waiting for a container. */
            snippetHtml: null,

            iframeLoading: false,
            iframeMounted: false,

            /** @inheritdoc */
            initialize: function () {
                var self = this;

                this._super();

                // Declared whatever the mode, so the template never binds against undefined
                this.isIframeBusy = ko.observable(false);

                if (!this.isIframeMode()) {
                    return this;
                }

                // Covers the ways the method becomes selected that do not go through the radio
                // button: a restored selection, and a store where Qliro is the only method and
                // Magento selects it on its own.
                this.selectedMethodSubscription = quote.paymentMethod.subscribe(function (method) {
                    if (method && method.method === self.getCode()) {
                        self.loadSnippet();
                    } else if (self.iframeMounted || self.iframeLoading) {
                        // Covers a fetch still in flight: without this its response would mount a
                        // live widget into the panel of a method the buyer has already left.
                        self.teardownIframe();
                    }
                });

                if (this.isSelected()) {
                    this.loadSnippet();
                }

                // A physical cart is only payable once a delivery method is chosen, and Qliro is
                // sent that one method. Magento restores the last used payment method from local
                // storage while the page is still loading, so without this a returning buyer would
                // have a Qliro order created from a cart that has no delivery method yet.
                this.shippingMethodSubscription = quote.shippingMethod.subscribe(function () {
                    if (!self.isSelected()) {
                        return;
                    }

                    if (self.iframeMounted) {
                        qliro.updateCart();
                    } else {
                        self.loadSnippet();
                    }
                });

                // The buyer can step back and edit the address after the iframe is mounted. The
                // standalone checkout watches this for the same reason.
                this.shippingAddressSubscription = quote.shippingAddress.subscribe(function () {
                    if (self.iframeMounted && self.isSelected()) {
                        qliro.updateCart();
                    }
                });

                // Magento rewrites this section for reasons that are not a cart change, a reload of
                // invalidated section data among them, and each one would cost a quote update. The
                // section's own revision tells a real change from a rewrite of the same cart.
                this.cartRevision = (customerData.get('cart')() || {}).data_id;

                this.cartSubscription = customerData.get('cart').subscribe(function (cart) {
                    var revision = (cart || {}).data_id;

                    if (revision === self.cartRevision) {
                        return;
                    }

                    self.cartRevision = revision;

                    if (self.iframeMounted && self.isSelected()) {
                        qliro.updateCart();
                    }
                });

                return this;
            },

            /**
             * The method renders as an iframe in place, rather than sending the buyer to the
             * standalone Qliro checkout page.
             *
             * @returns {Boolean}
             */
            isIframeMode: function () {
                return qliro.isIframeMode();
            },

            /**
             * Qliro is the payment method currently selected.
             *
             * @returns {Boolean}
             */
            isSelected: function () {
                var method = quote.paymentMethod();

                return !!method && method.method === this.getCode();
            },

            /**
             * Whether the cart is far enough along to create a Qliro order from. A physical cart
             * needs the delivery method Qliro is going to be sent, and locked to.
             *
             * @returns {Boolean}
             */
            isQuotePayable: function () {
                return quote.isVirtual() || !!quote.shippingMethod();
            },

            /**
             * Captures the mount point. Bound through afterRender, so it arrives once, whether or
             * not the snippet is already here.
             *
             * @param {HTMLElement} element
             */
            setIframeContainer: function (element) {
                this.iframeContainer = element;
                this.renderSnippet();
            },

            /** @inheritdoc */
            selectPaymentMethod: function () {
                this._super();

                if (this.isIframeMode()) {
                    this.loadSnippet();
                }

                return true;
            },

            /**
             * Fetch the snippet on demand. The q1Ready handlers have to be in place before the
             * bootstrap script inside the snippet runs, so they are registered first.
             */
            loadSnippet: function () {
                var self = this;

                if (this.iframeLoading || this.iframeMounted || !this.isQuotePayable()) {
                    return;
                }

                this.iframeLoading = true;
                this.isIframeBusy(true);
                qliro.registerCallbacks();

                $.ajax({
                    url: config.getSnippetUrl + '?token=' + encodeURIComponent(config.securityToken),
                    method: 'POST'
                }).always(function () {
                    self.iframeLoading = false;
                    self.isIframeBusy(false);
                }).then(
                    function (data) {
                        // The buyer left Qliro while this was in flight. Tearing down cannot
                        // cancel the request, so the response is dropped here instead of mounting
                        // a live widget into the panel of a method that is no longer chosen.
                        if (!self.isSelected()) {
                            return;
                        }

                        // The buyer already paid and came back, so the order is on its way and the
                        // pending page is the one that waits for it.
                        if (data && data.redirect) {
                            window.location = data.redirect;

                            return;
                        }

                        self.snippetHtml = (data && data.snippet) || '';
                        self.renderSnippet();
                    },
                    function (response) {
                        var data = response.responseJSON || {};

                        self.messageContainer.addErrorMessage({
                            message: data.error || $t('Qliro checkout could not be loaded. Please try again.')
                        });
                    }
                );
            },

            /**
             * Move the snippet into the page. A script node built by innerHTML never executes, so
             * each one is rebuilt as a real element.
             */
            renderSnippet: function () {
                var container = this.iframeContainer,
                    fragment,
                    nonce;

                if (this.iframeMounted || !container || !this.snippetHtml) {
                    return;
                }

                fragment = document.createElement('div');
                fragment.innerHTML = this.snippetHtml;
                nonce = this.getCspNonce();

                container.innerHTML = '';

                Array.prototype.slice.call(fragment.childNodes).forEach(function (node) {
                    var script;

                    if (node.nodeName !== 'SCRIPT') {
                        container.appendChild(node);

                        return;
                    }

                    script = document.createElement('script');

                    if (node.type) {
                        script.type = node.type;
                    }

                    // Magento's CSP blocks an inline script without the page nonce. A server
                    // rendered snippet is given one, a script built here is not.
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
                });

                // Set last: a throw above leaves the flag down, so the buyer can retry by
                // choosing the method again instead of facing an empty panel for good.
                this.iframeMounted = true;
            },

            /**
             * The nonce the page is serving its own scripts with, or an empty string when the
             * store runs no CSP. The snippet then loads only if the store allows inline scripts.
             *
             * @returns {String}
             */
            getCspNonce: function () {
                var scripts = document.querySelectorAll('script'),
                    i;

                for (i = 0; i < scripts.length; i++) {
                    if (scripts[i].nonce) {
                        return scripts[i].nonce;
                    }
                }

                qliro.debug('No CSP nonce on the page, the snippet scripts may be blocked');

                return '';
            },

            /**
             * Drop the iframe when the buyer moves to another payment method, so a stale widget
             * cannot keep talking to a checkout that is no longer on screen.
             */
            teardownIframe: function () {
                if (this.iframeContainer) {
                    this.iframeContainer.innerHTML = '';
                }

                // A no-op rather than null: the bootstrap script may still be in flight, and
                // calling null throws where calling this does nothing.
                window.q1Ready = function () {};
                window.q1 = null;

                // The handlers that were bound to that widget go with it, so the next one binds
                // its own instead of being left with none.
                qliro.forgetCheckout();

                this.iframeMounted = false;
                this.iframeLoading = false;
                this.snippetHtml = null;
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
