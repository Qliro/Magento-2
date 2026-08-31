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
    'Magento_Checkout/js/action/get-payment-information',
    'Magento_Checkout/js/model/shipping-service'
], function(
    $,
    config,
    quote,
    customerData,
    __,
    checkoutData,
    addressConverter,
    selectShippingAddress,
    getPaymentInformationAction,
    shippingService
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
    /**
     * Whether Magento has actually produced shipping rates for the current address.
     *
     * An address equal to the quote's does NOT mean the rates behind it were ever calculated:
     * the quote can already carry the full address, written when the Qliro order was created,
     * while shippingRates() is still empty and no rate request has been made. Selecting the
     * address is what triggers that request, so equality alone is not enough to skip it.
     */
    function hasShippingRates() {
        var rates = shippingService.getShippingRates();

        return !!rates && rates().length > 0;
    }

    function syncShippingAddress(addressData) {
        if (!addressData || !addressData.postcode) {
            qliroDebug('No address stored for the quote yet', addressData);

            return false;
        }

        // Skip only when the address matches AND the rates behind it exist. Skipping on equality
        // alone left a store whose quote was already complete with no rate request at all, so no
        // delivery methods appeared until the customer edited the address or reloaded the page.
        if (isSameAsQuoteAddress(addressData) && hasShippingRates()) {
            qliroDebug('Shipping address already in sync and rates are loaded', addressData);

            return true;
        }

        if (isSameAsQuoteAddress(addressData)) {
            qliroDebug('Address matches the quote but no rates yet, selecting it to trigger them', addressData);
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
    var lockDeferred = false;
    var sawMismatch = false;
    var refreshInFlight = false;
    var refreshQueued = false;

    /**
     * window.q1 is created by Qliro's own script, loaded by the snippet on this page, and nothing
     * sequences that against our handlers: updateCart can run during page initialisation, before
     * it exists. Calling into it then threw "Cannot read properties of undefined (reading 'lock')",
     * and because that happened inside a promise callback it abandoned everything after it in the
     * same callback, including the address sync. So every use goes through here (PLIN-376).
     */
    function qliroCheckout() {
        return window.q1 && typeof window.q1.lock === 'function' ? window.q1 : null;
    }

    // Long enough that a normal order update wins the race, short enough that a customer does
    // not sit in front of a frozen checkout wondering.
    var UNLOCK_WATCHDOG_MS = 10000;

    function lockCheckout() {
        if (config.isEagerCheckoutRefresh) {
            qliroDebug('Skipping checkout lock.');

            return;
        }

        var checkout = qliroCheckout();

        if (!checkout) {
            // Nothing to lock yet, the iframe is not on the page, so there is no way for the
            // customer to pay against a stale total. Remembered rather than dropped, so the lock
            // is applied when the checkout announces itself instead of being lost silently.
            lockDeferred = true;
            qliroDebug('Qliro checkout not initialised yet, lock deferred');

            return;
        }

        lockDeferred = false;
        checkout.lock();
    }

    function unlockCheckout(reason) {
        clearTimeout(unlockWatchdog);
        unlockWatchdog = null;
        lockDeferred = false;

        if (config.isEagerCheckoutRefresh) {
            qliroDebug('Skipping checkout unlock.', reason);

            return;
        }

        var checkout = qliroCheckout();

        if (!checkout) {
            qliroDebug('Qliro checkout not initialised, nothing to unlock', reason);

            return;
        }

        qliroDebug('Unlocking checkout', reason);
        checkout.unlock();
    }

    function armUnlockWatchdog() {
        if (config.isEagerCheckoutRefresh) {
            return;
        }

        sawMismatch = false;
        clearTimeout(unlockWatchdog);
        unlockWatchdog = setTimeout(function() {
            unlockWatchdog = null;

            // The dangling lock this watchdog exists for is the refresh that produces no order
            // update at all. An order that did arrive and disagreed with the store is the opposite
            // case: unlocking on it would let the customer pay a total we already know is wrong,
            // and silently, since the message only shows on the fourth mismatch. So the iframe
            // stays locked and says why.
            if (sawMismatch) {
                qliroDebug('Order updates arrived and none matched, keeping the checkout locked');
                showErrorMessage(__('Store and Qliro One totals don\'t match. Refresh the page.'));

                return;
            }

            unlockCheckout('no order update within ' + UNLOCK_WATCHDOG_MS + 'ms');
        }, UNLOCK_WATCHDOG_MS);
    }

    function bindOrderUpdated() {
        if (orderUpdatedBound) {
            return;
        }

        var checkout = qliroCheckout();

        if (!checkout) {
            // Bound from onCheckoutLoaded instead, which cannot run before the script exists.
            qliroDebug('Qliro checkout not initialised yet, order updates not bound');

            return;
        }

        orderUpdatedBound = true;

        checkout.onOrderUpdated(function(order) {
            if (config.isEagerCheckoutRefresh) {
                qliroDebug('Skipping checkout update polling.');

                return true;
            }

            if (expectedTotalPrice === null) {
                return true;
            }

            if (Math.abs(order.totalPrice - expectedTotalPrice) < 0.005) {
                unmatchCount = 0;
                sawMismatch = false;
                unlockCheckout('totals match');
            } else {
                sawMismatch = true;
                unmatchCount++;

                if (unmatchCount > 3) {
                    unmatchCount = 0;
                    showErrorMessage(__('Store and Qliro One totals don\'t match. Refresh the page.'));
                }
            }
        });
    }

    /**
     * Run at most one quote update at a time, and one more after it if anything asked while it was
     * in flight.
     *
     * onCustomerInfoChanged fires per masked-address payload, and nothing here knows whether Qliro
     * debounces it. Without this guard every keystroke that reaches it would take its own lock, its
     * own round trip and its own watchdog, and they would interleave: an early response setting
     * expectedTotalPrice after a later one, a watchdog armed for an update that has already been
     * superseded. The trailing run is what keeps the last state from being dropped.
     */
    function refreshCart() {
        if (refreshInFlight) {
            refreshQueued = true;
            qliroDebug('Quote update already in flight, queued one to follow it');

            return;
        }

        refreshInFlight = true;
        lockCheckout();

        sendUpdateQuote()
            .then(
                function(data) {
                    expectedTotalPrice = data && data.order ? data.order.totalPrice : null;
                    bindOrderUpdated();
                    armUnlockWatchdog();
                    settleRefresh();
                },
                function(response, state, reason) {
                    var data = response.responseJSON || {};

                    unlockCheckout('quote update failed');
                    showErrorMessage(data.error || reason);
                    settleRefresh();
                }
            );
    }

    function settleRefresh() {
        refreshInFlight = false;

        if (refreshQueued) {
            refreshQueued = false;
            refreshCart();
        }
    }

    return {
        updateCart: refreshCart,

        onCheckoutLoaded: function() {
            qliroSuccessDebug('onCheckoutLoaded', window.q1);

            // The script exists by definition here, so anything that had to wait for it is applied
            // now: an order-update handler that could not be bound, and a lock that was asked for
            // before the iframe was on the page. The watchdog is re-armed with the lock so a
            // deferred one cannot outlive the update it was taken for.
            bindOrderUpdated();

            if (lockDeferred) {
                lockCheckout();
                armUnlockWatchdog();
            }
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

