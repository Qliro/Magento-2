/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

define([
    'jquery',
    'Qliro_QliroOne/js/model/config',
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/customer-data',
    'mage/translate',
    'Magento_Checkout/js/model/checkout-data-resolver',
    'Magento_Checkout/js/action/get-payment-information'
], function(
    $,
    config,
    quote,
    customerData,
    __,
    checkoutDataResolver,
    getPaymentInformationAction
) {
    function sendUpdateQuote() {
        return (
            $.ajax({
                url: config.updateQuoteUrl + '?quote_id=' + quote.getQuoteId() + '&token=' + config.securityToken,
                method: 'POST'
            })
        )
    }

    function sendAjaxAsJson(url, data) {
        qliroDebug('Calling sendAjaxAsJson', data);
        return $.ajax({
            url: url + '?token=' + config.securityToken,
            method: 'POST',
            data: JSON.stringify(data),
            processData: false,
            contentType: 'application/json'
        });
    }

    function showErrorMessage(message) {
        qliroDebug('Calling showErrorMessage', message);
        customerData.set('messages', {
            messages: [{
                type: 'error',
                text: message
            }]
        });
    }

    function qliroDebug(caption, data) {
        if (config.isDebug) {
            console.log(caption, data);
        }
    }

    function qliroSuccessDebug(caption, data) {
        qliroDebug('Success: ' + caption, data);
    }

    function updateTotals() {
        getPaymentInformationAction();
    }

    function isQliroActive() {
        var method = quote.paymentMethod();

        return !!method && method.method === 'qliroone';
    }

    var model = {
        updateCart: function() {
            if (config.isEagerCheckoutRefresh) {
                qliroDebug('Skipping checkout lock.');
                sendUpdateQuote().fail(function(response) {
                    var data = response.responseJSON || {};
                    showErrorMessage(data.error || __('Something went wrong while updating cart.'));
                });
                return;
            }

            if (!window.q1) {
                qliroDebug('updateCart skipped: no active Qliro widget');
                return;
            }

            window.q1.lock();

            var unlocked = false;
            var unlock = function() {
                if (unlocked) return true;
                unlocked = true;
                try { window.q1.unlock(); } catch (e) {
                    qliroDebug('q1.unlock threw', e);
                }
                return true; // signal Q1 to drop the onOrderUpdated handler
            };

            window.q1.onOrderUpdated(function() {
                return unlock();
            });

            // Safety: if onOrderUpdated never fires (Q1 race, version mismatch, etc.)
            // unlock anyway so the widget can never stay locked indefinitely.
            setTimeout(unlock, 5000);

            sendUpdateQuote().fail(function(response, state, reason) {
                var data = response.responseJSON || {};
                unlock();
                showErrorMessage(data.error || reason || __('Something went wrong while updating cart.'));
            });
        },

        onCheckoutLoaded: function() {
            qliroSuccessDebug('onCheckoutLoaded', q1);
        },

        onCustomerInfoChanged: function(customer) {
            sendAjaxAsJson(config.updateCustomerUrl, customer).then(
                function(data) {
                    qliroSuccessDebug('onCustomerInfoChanged', data);
                    var shippingAddress = quote.shippingAddress();
                    if (!shippingAddress || !shippingAddress.postcode) {
                        checkoutDataResolver.resolveShippingAddress();
                    }
                    model.updateCart();
                },
                function(response) {
                    var data = response.responseJSON || {};
                    var error = data.error || __('Something went wrong while updating customer.');
                    showErrorMessage(error);
                }
            );
        },

        onPaymentDeclined: function(declineReason) {
            if (isQliroActive()) {
                $(".opc-block-summary").show();
                $(".discount-code").show();
            }
            qliroSuccessDebug('onPaymentDeclined', declineReason);
        },

        onPaymentMethodChanged: function(paymentMethod) {
            sendAjaxAsJson(config.updatePaymentMethodUrl, paymentMethod).then(
                function(data) {
                    qliroSuccessDebug('onPaymentMethodChanged', data);
                    updateTotals();
                },
                function(response) {
                    var data = response.responseJSON || {};
                    var error = data.error || __('Something went wrong while updating payment method.');
                    showErrorMessage(error);
                }
            );
        },

        onPaymentProcessStart: function() {
            if (isQliroActive()) {
                $(".opc-block-summary").hide();
                $(".discount-code").hide();
            }
            qliroSuccessDebug('onPaymentProcessStart', q1);
        },

        onPaymentProcessEnd: function() {
            if (isQliroActive()) {
                $(".opc-block-summary").show();
                $(".discount-code").show();
            }
            qliroSuccessDebug('onPaymentProcessEnd', q1);
        },

        onSessionExpired: function() {
            qliroSuccessDebug('onSessionExpired', q1);
            if (isQliroActive()) {
                window.location.reload();
            }
        },

        onShippingMethodChanged: function(shipping) {
            sendAjaxAsJson(config.updateShippingMethodUrl, shipping).then(
                function(data) {
                    qliroSuccessDebug('onShippingMethodChanged', data);
                    updateTotals();
                },
                function(response) {
                    var data = response.responseJSON || {};
                    var error = data.error || __('Something went wrong while updating shipping method.');
                    showErrorMessage(error);
                }
            );
        },

        onShippingPriceChanged: function(newShippingPrice) {
            sendAjaxAsJson(config.updateShippingPriceUrl, {newShippingPrice: newShippingPrice}).then(
                function(data) {
                    qliroSuccessDebug('onShippingPriceChanged', data);
                    updateTotals();
                },
                function(response) {
                    var data = response.responseJSON || {};
                    var error = data.error || __('Something went wrong while updating shipping method options.');
                    showErrorMessage(error);
                }
            );
        }
    };

    return model;
});
