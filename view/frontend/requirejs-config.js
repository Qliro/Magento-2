var config = {
    map: {
        '*': {
            pollPending: 'Qliro_QliroOne/js/poll-pending'
        }
    },
    config: {
        mixins: {
            "Magento_Checkout/js/view/shipping": {
                "Qliro_QliroOne/js/mixins/shipping": true
            },
            "Magento_Checkout/js/view/summary/abstract-total": {
                "Qliro_QliroOne/js/mixins/abstract-total": true
            }
        }
    }
};