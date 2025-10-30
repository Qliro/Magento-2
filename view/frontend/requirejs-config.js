var config = {
    map: {
        '*': {
            pollPending: 'Qliro_QliroOne/js/poll-pending',
            qliroExpiryListener: 'Qliro_QliroOne/js/expiry-listener'
        }
    },
    config: {
        mixins: {
            "Magento_Checkout/js/view/shipping": {
                "Qliro_QliroOne/js/mixins/shipping": true
            }
        }
    }
};