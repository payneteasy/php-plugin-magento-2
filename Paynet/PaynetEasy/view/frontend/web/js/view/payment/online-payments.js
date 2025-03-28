/* @api */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push(
        {
            type: 'paynet_payneteasy',
            component: 'Paynet_PaynetEasy/js/view/payment/method-renderer/payneteasy'
        },
    );

    /** Add view logic here if needed */
    return Component.extend({});
});
