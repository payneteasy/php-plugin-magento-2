define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Checkout/js/model/full-screen-loader',
        'mage/translate',
        'mage/url',
        'Magento_Ui/js/model/messageList'
    ],
    function (
        $,
        Component,
        placeOrderAction,
        selectPaymentMethodAction,
        customer,
        checkoutData,
        additionalValidators,
        errorProcessor,
        fullScreenLoader,
        $t,
        url,
        globalMessageList
    ) {
        'use strict';

        return Component.extend({
            redirectAfterPlaceOrder: false,
            defaults: {
                template: 'Paynet_PaynetEasy/payment/payneteasy'
            },

            /**
             * Fetches and returns the instructions for the payment method from the checkout configuration.
             *
             * @return {*} The instructions for the payment method.
             * @return {*} The card payment form if direct integration method for the payment method.
             */
            getInstructions: function () {
                return window.checkoutConfig.payment.instructions[this.item.method];
            },

            isDirectMethod: function () {
                if (window.checkoutConfig.payment.payment_payneteasy_payment_method[this.item.method] == 'direct') {
                    return true;
                } else if (window.checkoutConfig.payment.payment_payneteasy_payment_method[this.item.method] == 'form') {
                    return false;
                }
            },

            isTestMode: function () {
                return window.checkoutConfig.payment.payment_payneteasy_test_mode[this.item.method];
            },

            getCode: function() {
                return this.item.method;
            },
            getData: function() {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'credit_card_number': $('#paynet_payneteasy_credit_card_number').val(),
                        'card_printed_name': $('#paynet_payneteasy_card_printed_name').val(),
                        'expire_month': $('#paynet_payneteasy_expire_month').val(),
                        'expire_year': $('#paynet_payneteasy_expire_year').val(),
                        'cvv2': $('#paynet_payneteasy_cvv2').val()
                    }
                };
            },
            /**
             * Initiates the placement of the order.
             *
             * @param {Object} data - The form data.
             * @param {Event} event - The form submission event.
             * @return {boolean} Whether or not the order placement was successful.
             */
            placeOrder: function (data, event) {
                if (event)
                    event.preventDefault();
                var self = this,
                    placeOrder,
                    emailValidationResult = customer.isLoggedIn(),
                    loginFormSelector = 'form[data-role=email-with-possible-login]';
                if (!customer.isLoggedIn()) {
                    $(loginFormSelector).validation();
                    emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid());
                }
                if (emailValidationResult && this.validate()) {
                    this.isPlaceOrderActionAllowed(false);
                    placeOrder = placeOrderAction(this.getData(), false, this.messageContainer);

                    $.when(placeOrder).done(function () {
                        self.isPlaceOrderActionAllowed(true);
                    }).done(this.afterPlaceOrder.bind(this));
                    return true;
                }
                return false;
            },

            /**
             * Selects the current payment method.
             *
             * @return {boolean} Always returns true.
             */
            selectPaymentMethod: function () {
                selectPaymentMethodAction(this.getData());
                checkoutData.setSelectedPaymentMethod(this.item.method);
                return true;
            },

            /**
             * Executes after an order has been placed. Initiates the payment redirect.
             */
            afterPlaceOrder: function () {
                fullScreenLoader.startLoader();
                $.post(url.build('payneteasy/payment/redirect'), 'json')
                    .done(data => {
                        window.location = data.url;
                    }).fail(response => {
                    console.log(this.messageContainer);
                    errorProcessor.process('error', this.messageContainer);
                }).always(() => {
                    fullScreenLoader.stopLoader();
                });
            }
        });
    }
);