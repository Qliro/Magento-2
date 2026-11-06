define([
    'jquery',
    'Magento_Ui/js/modal/confirm'
], function ($, modalConfirm) {
    'use strict';

    /**
     * Asks before the form it is bound to is submitted. Bound per form, so a page with several
     * subscriptions submits the one whose button was clicked.
     */
    return function (config, element) {
        $(element).find(config.buttonSelector).on('click', function () {
            modalConfirm({
                content: config.content,
                buttons: [{
                    text: config.cancelText,
                    class: 'action-secondary action-dismiss',
                    click: function (event) {
                        this.closeModal(event);
                    }
                }, {
                    text: config.confirmText,
                    class: 'action-primary action-accept',
                    click: function (event) {
                        this.closeModal(event, true);
                    }
                }],
                actions: {
                    confirm: function () {
                        element.submit();
                    }
                }
            });

            return false;
        });
    };
});
