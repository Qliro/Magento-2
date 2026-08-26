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
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/address-converter',
    'Magento_Checkout/js/action/select-shipping-address',
    'Magento_Checkout/js/action/get-payment-information'
], function(
    $,
    config,
    quote,
    customerData,
    __,
    checkoutData,
    addressConverter,
    selectShippingAddress,
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

    function joinStreet(street) {
        return Array.isArray(street) ? street.join(', ') : (street || '');
    }

    function isSameAsQuoteAddress(addressData) {
        var current = quote.shippingAddress();

        return !!current &&
            current.postcode === addressData.postcode &&
            current.city === addressData.city &&
            current.countryId === addressData.country_id &&
            joinStreet(current.street) === joinStreet(addressData.street);
    }

    /**
     * Put the address Magento stored for the quote into the client side quote.
     *
     * This checkout has no address form, so nothing else ever fills the client side
     * address. Without this the shipping rate estimation runs on an empty address and
     * returns no shipping methods on the first attempt.
     */
    function syncShippingAddress(addressData) {
        if (!addressData || !addressData.postcode) {
            qliroDebug('No address stored for the quote yet', addressData);

            return false;
        }

        if (isSameAsQuoteAddress(addressData)) {
            qliroDebug('Shipping address already in sync', addressData);

            return true;
        }

        checkoutData.setShippingAddressFromData(addressData);
        selectShippingAddress(addressConverter.formAddressDataToQuoteAddress(addressData));

        return true;
    }

    // The iframe is locked while the store updates the quote and released when Qliro reports an
    // order whose total matches ours. Both sides of that live at module scope on purpose:
    //
    // - The unlock CANNOT depend only on an order update arriving. refreshCart() is also called
    //   when the store had nothing to apply, so there may be no quote change for Qliro to report
    //   and no onOrderUpdated to release the lock. Without the watchdog below the customer is
    //   left with a permanently frozen checkout, and since eager_checkout_refresh defaults to
    //   off, locking is the default path rather than an edge case.
    // - The handler is registered ONCE and compares against the CURRENT expected total. Keeping
    //   the counter and the total inside the callback gave every refresh its own copy of both,
    //   so a second refresh compared Qliro's order against a total captured earlier and counted
    //   mismatches on a counter nobody else could reset.
    var unlockWatchdog = null;
    var expectedTotalPrice = null;
    var unmatchCount = 0;
    var orderUpdatedBound = false;

    // Long enough that a normal order update wins the race, short enough that a customer does
    // not sit in front of a frozen checkout wondering.
    var UNLOCK_WATCHDOG_MS = 10000;

    function lockCheckout() {
        if (config.isEagerCheckoutRefresh) {
            qliroDebug('Skipping checkout lock.');

            return;
        }

        window.q1.lock();
    }

    function unlockCheckout(reason) {
        clearTimeout(unlockWatchdog);
        unlockWatchdog = null;

        if (config.isEagerCheckoutRefresh) {
            qliroDebug('Skipping checkout unlock.', reason);

            return;
        }

        qliroDebug('Unlocking checkout', reason);
        window.q1.unlock();
    }

    function armUnlockWatchdog() {
        if (config.isEagerCheckoutRefresh) {
            return;
        }

        clearTimeout(unlockWatchdog);
        unlockWatchdog = setTimeout(function() {
            unlockCheckout('no matching order update within ' + UNLOCK_WATCHDOG_MS + 'ms');
        }, UNLOCK_WATCHDOG_MS);
    }

    function bindOrderUpdated() {
        if (orderUpdatedBound) {
            return;
        }

        orderUpdatedBound = true;

        window.q1.onOrderUpdated(function(order) {
            if (config.isEagerCheckoutRefresh) {
                qliroDebug('Skipping checkout update polling.');

                return true;
            }

            if (expectedTotalPrice === null) {
                return true;
            }

            if (Math.abs(order.totalPrice - expectedTotalPrice) < 0.005) {
                unmatchCount = 0;
                unlockCheckout('totals match');
            } else {
                unmatchCount++;

                if (unmatchCount > 3) {
                    unmatchCount = 0;
                    showErrorMessage(__('Store and Qliro One totals don\'t match. Refresh the page.'));
                }
            }
        });
    }

    function refreshCart() {
        lockCheckout();

        sendUpdateQuote()
            .then(
                function(data) {
                    expectedTotalPrice = data && data.order ? data.order.totalPrice : null;
                    bindOrderUpdated();
                    armUnlockWatchdog();
                },
                function(response, state, reason) {
                    var data = response.responseJSON || {};

                    unlockCheckout('quote update failed');
                    showErrorMessage(data.error || reason);
                }
            );
    }

    return {
        updateCart: refreshCart,

        onCheckoutLoaded: function() {
            qliroSuccessDebug('onCheckoutLoaded', q1);
        },

        onCustomerInfoChanged: function(customer) {
            sendAjaxAsJson(config.updateCustomerUrl, customer).then(
                function(data) {
                    qliroSuccessDebug('onCustomerInfoChanged', data);

                    if (syncShippingAddress(data && data.address)) {
                        return;
                    }

                    // Qliro masks the address in this payload, so the store can only learn it
                    // by fetching the order, and selecting an address is what normally triggers
                    // that. With nothing to select the refresh has to be asked for directly.
                    refreshCart();
                },
                function(response) {
                    var data = response.responseJSON || {};
                    var error = data.error || __('Something went wrong while updating customer.');
                    showErrorMessage(error);
                }
            );
        },

        onPaymentDeclined: function(declineReason) {
            $(".opc-block-summary").show();
            $(".discount-code").show();
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
            $(".opc-block-summary").hide();
            $(".discount-code").hide();
            sendAjaxAsJson(config.lockQuoteUrl, {quoteId: quote.getQuoteId()}).then(
                function(data) {
                    qliroSuccessDebug('Quote is locked', data);
                },
                function(response) {
                    qliroDebug('Failed to lock quote', response);
                }
            );
            qliroSuccessDebug('onPaymentProcessStart', q1);
        },

        onPaymentProcessEnd: function() {
            $(".opc-block-summary").show();
            $(".discount-code").show();
            sendAjaxAsJson(config.unlockQuoteUrl, {quoteId: quote.getQuoteId()}).then(
                function(data) {
                    qliroSuccessDebug('Quote is unlocked', data);
                },
                function(response) {
                    qliroDebug('Failed to unlock quote', response);
                }
            );
            qliroSuccessDebug('onPaymentProcessEnd', q1);
        },

        onSessionExpired: function() {
            qliroSuccessDebug('onSessionExpired', q1);
            window.location.reload();
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
    }
});

