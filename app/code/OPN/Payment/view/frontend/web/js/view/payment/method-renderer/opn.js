define([
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'mage/validation',
    'ko',
    'Magento_Checkout/js/model/totals',
    'Magento_Checkout/js/model/step-navigator',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/select-billing-address',
    'Magento_Checkout/js/model/address-converter',
    'mage/translate'
], function (Component, $, validation, ko, totals, stepNavigator ,quote, selectBillingAddress, addressConverter, $t) {
    'use strict';

    var config = window.checkoutConfig?.payment?.qidigitalpayment || {};
    var methodLoaded = {};

    var methods = config.methods || ['card'];
    return Component.extend({
        defaults: {
            template: 'OPN_Payment/payment/opn',
            title: config.title || 'Open Payment Network',
            gatewayUrl: config.gateway_url || null,
            merchantId: config.merchant_id || null,
            integrationType: config.integration_type || 'direct',
            saveCardEnabled: config.save_card_enabled == '1',
            token: '',
            ccv: '',
            ppro_option: '',
            nomupay_option: '',
            cardId: config.has_cards ? config.saved_cards[0].entity_id : 'new',
            saveCard: false,
            hasCards: config.has_cards,
            savedCards: config.saved_cards,
            methods: methods,
            method: methods[0] || 'card',
            cartTotals: {},
            methodsConfig: config.methods_config,
            currencyCode: config.currency,
            paymentOptions: config.payment_options || {},
        },

        __: function (s){
            return $.mage.__($t(s.toString().trim()));
        },

        getCartTotals: function(){
            if( totals.totals() )
                this.cartTotals = totals.totals();
        },

        getIntegrationType: function () {
            return this.integrationType;
        },

        getPaymentOptions: function(m){
            return [...[{code:'', name: `[ ${this.__("Select")} ]`}],...(this.paymentOptions[m] || [])];
        },

        hasCards: function () {
            return this.hasCards;
        },

        changeCard: function(obj, event){
            if( event.target.value == 'new' ){
                this.loadMethodData('card');
            }
        },

        hasMoreMethod: function(){
            return this.methods.length > 1;
        },

        hasAvailMethod: function(m){
            return this.methods.includes(m);
        },

        displayButton: function(m){
            return !['applepay','googlepay'].includes(this.method());
        },

        changeMethod: function(obj, event){
            
            this.ppro_option('');
            this.nomupay_option('');
            this.showError();
            this.loadMethodData(event.target.value);
        },

        showError: function(msg=null){
            var elm = $('.qidigital-payment-method div[data-role="checkout-messages"]');
            elm.find('.error-qd').remove();
            if( msg ){
                msg = msg.replace('InvalidAccessError:','').trim();
                if( !elm.hasClass('qd-msg') )
                    $("body").append('<style>.qd-msg{ display:block !important;</style>');
    
                elm.addClass('qd-msg');
                if (elm[0]) elm[0].style.display = 'block';
                var $msg = $('<div data-ui-id="checkout-cart-validationmessages-message-error"></div>').text(msg);
                if( elm.find('.message-error').length )
                    elm.find('.message-error').empty().append($msg);
                else
                    elm.append($('<div aria-atomic="true" role="alert" class="message message-error error error-qd"></div>').append($msg));
            }
        },

        initialize: function () {
            this._super();
            var ths = this;

            if (this.integrationType === 'embedded') {
                this.redirectAfterPlaceOrder = false;
            }

            // Express Checkout shortcut: when arriving from a "Buy with Apple/Google Pay"
            // button on the cart page or mini-cart (OPN_Payment::shortcut/buttons.phtml,
            // link built via ?opn_express=applepay|googlepay), pre-select that wallet.
            var expressParam = new URLSearchParams(window.location.search).get('opn_express');
            if (expressParam && ['applepay', 'googlepay'].includes(expressParam) && methods.includes(expressParam)) {
                this.expressMethod = expressParam;
                if (window.history && window.history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('opn_express');
                    window.history.replaceState({}, document.title, url.toString());
                }
            }

            quote.paymentMethod.subscribe(function (paymentMethod) {
                if (paymentMethod && paymentMethod.method == 'qidigitalpayment')
                   ths.changeMethod(null,{ target:{ value: ths.expressMethod || methods[0] }});
            });

            stepNavigator.steps.subscribe(function (steps) {
                var activeStep = stepNavigator.getActiveItemIndex();
                if( steps[activeStep] && steps[activeStep].code === 'payment' ){
                    ths.getCartTotals();
                    setTimeout(()=>ths.loadMethodData(ths.method()),50);
                }
            });

            if (quote.isVirtual()) {
                quote.billingAddress.subscribe(function() {
                    ths.getCartTotals();
                    setTimeout(()=>ths.loadMethodData(ths.method()),50);
                });
            }

            setTimeout(function(){
                ths.getCartTotals();
                if( config.has_cards )
                    $("#inp_opn_card_"+ths.cardId()).prop("checked",true).trigger('change');
                if( ths.expressMethod )
                    ths.selectPaymentMethod();
            },200);

            $('.qidigital-payment-method div[data-role="checkout-messages"]').removeAttr('data-bind');

            setTimeout(()=>{
                if( methods[0] == 'card' && config.has_cards ){
                    this.initHostedForm('D');
                } else
                    this.changeMethod(null,{ target:{ value: methods[0] }});
            },500);
        
            $(document).on("click", '.qidigital-payment-method button.action.checkout', (e) => {
                this.showError();
                if (this.getIntegrationType() !== 'direct') {
                    // For hosted / modal / embedded: bypass the jQuery-Validate submitHandler
                    // (which may not be ready yet on virtual-product pages that skip shipping)
                    // and call placeOrder() directly via the hidden form button.
                    // validate() still runs inside placeOrder() and checks billing address.
                    e.preventDefault();
                    $('form[data-role=qidigital-form] button').trigger('click');
                }
            });

            $(document).on("change",'.opn_card_id',()=>this.ccv(''));

            // Initialize form validation after DOM is ready
            this.afterRender = function () {
                var formSelector = 'form[data-role=qidigital-form]';
                var form = $(formSelector);
                if (form.length) {
                    form.validation();
                }
            };
            return this;
        },

        onApplePayButtonClicked: function(){
    let ths = this;
    this.showError();

    try{
        let request = this.methodsConfig.applepay;
        request.total.amount = parseFloat(ths.cartTotals.grand_total || 0).toFixed(2);

        // Create ApplePaySession
        const session = new ApplePaySession(3, request);
        
        session.onpaymentauthorized = event => {
            const paymentData = event.payment.token.paymentData;
            const base64Token = btoa(JSON.stringify(paymentData));

            ths.token(base64Token);
            ths.cardId('new');

            // A guest can reach this point with the billing form open and empty; Magento
            // then refuses to place the order with field-level messages only — which is
            // what produced "authorize, ~20s of processing, then nothing" (reported
            // 27/08/2026, root cause confirmed 31/08/2026). The sheet now collects the
            // card's billing address (requiredBillingContactFields in ConfigProvider);
            // fill the quote from it before placing the order.
            ths.applyWalletBillingContact(event.payment.billingContact);

            // Complete the sheet from the REAL order-placement result, hooked in
            // getPlaceOrderDeferredObject(). The old logic watched the DOM for an error
            // box and told Apple SUCCESS after a 25s timeout whether or not an order
            // existed — reporting success on a timer is wrong however it is triggered.
            ths.walletAuthorized = function (ok) {
                session.completePayment({
                    status: ok ? ApplePaySession.STATUS_SUCCESS : ApplePaySession.STATUS_FAILURE
                });
            };

            if (ths.placeOrder() === false) {
                // Synchronous refusal — client-side validation. Close the sheet honestly
                // and say why nothing happened instead of 25 seconds of silence.
                ths.walletAuthorized = null;
                session.completePayment({ status: ApplePaySession.STATUS_FAILURE });
                ths.showError($t('Please complete all required checkout fields and try again.'));
            }
        };

        session.onvalidatemerchant = async event => {
            try {
                let resp = await fetch(`${config.verify_url}?validation_url=${encodeURI(event.validationURL)}`, {
                    method: 'GET',
                });
                
                if (!resp.ok)
                    throw new Error('Unable to verify merchant in ApplePay'); 

                resp = await resp.json(); 
                
                if( resp && resp.session )
                    session.completeMerchantValidation(resp.session);
                else 
                    throw new Error((resp.statusMessage || resp.message) || 'Unable to verify merchant in ApplePay'); 

            } catch(error){
                ths.showError(error.message);
                session.abort();  
            }
        };
        
        session.begin();

    } catch (e) {
        ths.showError(e.message);
    }
},

        /**
         * Fill the quote's billing address from a wallet billing contact
         * (Apple Pay ApplePayPaymentContact shape) — but only when the shopper
         * hasn't set one, so signed-in flows and "same as shipping" are untouched.
         * Telephone/name gaps are borrowed from the shipping address: wallet billing
         * contacts carry no phone number, and Magento requires one by default.
         */
        applyWalletBillingContact: function (contact) {
            if (quote.billingAddress() || !contact) {
                return;
            }
            var shippingAddr = quote.shippingAddress() || {};
            try {
                selectBillingAddress(addressConverter.formAddressDataToQuoteAddress({
                    firstname: contact.givenName || shippingAddr.firstname || '',
                    lastname: contact.familyName || shippingAddr.lastname || '',
                    street: (contact.addressLines && contact.addressLines.length)
                        ? contact.addressLines
                        : (shippingAddr.street || []),
                    city: contact.locality || shippingAddr.city || '',
                    postcode: contact.postalCode || shippingAddr.postcode || '',
                    country_id: (contact.countryCode || shippingAddr.countryId || '').toUpperCase(),
                    // ponytail: region passed as free text — no region_id lookup. Fine for
                    // GB and most of Europe; add a directory lookup if a region-required
                    // country (US/CA) ever runs guest wallet checkout.
                    region: contact.administrativeArea || '',
                    telephone: contact.phoneNumber || shippingAddr.telephone || ''
                }));
            } catch (e) {
                // Leave it unset: placeOrder() will refuse and the sheet reports failure.
            }
        },

        /**
         * Wallet sheets (Apple Pay / Google Pay) park a one-shot callback in
         * walletAuthorized before triggering placeOrder; it is attached here, BEFORE
         * default.js adds its own .done() handler, so the sheet is told the true
         * outcome before redirect-on-success navigates away.
         */
        getPlaceOrderDeferredObject: function () {
            var deferred = this._super(),
                done = this.walletAuthorized;
            if (done) {
                this.walletAuthorized = null;
                deferred
                    .done(function () { done(true); })
                    .fail(function () { done(false); });
            }
            return deferred;
        },

        loadMethodJs: function (method){
            var ths = this;

            if( method == 'card'){
                // Hosted fields SDK is only needed for direct integration (inline card tokenisation).
                // For hosted / modal / embedded the payment form lives on the gateway side,
                // so skip the external script fetch entirely — it would violate strict CSP rules.
                if (this.getIntegrationType() !== 'direct') {
                    methodLoaded['card'] = true;
                    setTimeout(()=>this.initHostedForm('A'), 10);
                    return;
                }
                $.ajax({
                    url: 'https://gateway.cardstream.com/sdk/web/v1/js/hostedfields.min.js',
                    cache: false,
                    dataType: 'script',
                    beforeSend: function(jqXHR, settings) {
                        if( settings.url.indexOf('?') !== 0 )
                            settings.url = settings.url.split('?')[0];
                    },
                    success: ()=>{
                        $(document).ready($ =>{
                            setTimeout(()=>this.initHostedForm('A'),10);
                        });
                    },
                    error: () => { 
                        $("#payment_form_qidigitalpayment,.qidigital-payment-method button.action.checkout").hide();
                        ths.showError("Unable to initialize payment");  
                        $('.qidigital-payment-method button.action.checkout').hide();
                    },
                    async: true,
                    showLoader: true
                });
            }

            if( method == 'applepay' ){
                $.ajax({
                    url: 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js',
                    dataType: 'script',
                    crossDomain: true,
                    success: ()=>{
                        methodLoaded[method] = true;
                        ths.loadApplePay(0);
                    },
                    error: () => {
                        ths.showError("Unable to initialize payment");  
                    },
                    async: true,
                    showLoader: true
                });
            }

            if( method == 'googlepay' ){
                $.ajax({
                    url: 'https://pay.google.com/gp/p/js/pay.js',
                    dataType: 'script',
                    success: ()=>{
                        methodLoaded[method] = true;
                        ths.loadGooglePay(0);
                    },
                    error: () => {
                       ths.showError("Unable to initialize payment");  
                    },
                    async: true,
                    showLoader: true
                });
            }

        },

        loadMethodData: function(method){
            if( method == 'card' ){
                if( !methodLoaded[method] )
                    this.loadMethodJs(method);
                else
                    this.initHostedForm('A')
            }
            
            if( method == 'applepay' ){
                if( !methodLoaded[method] )
                    this.loadMethodJs(method);
                else
                    this.loadApplePay(1);
            }

            if( method == 'googlepay'){
                 if( !methodLoaded[method] )
                    this.loadMethodJs(method);
                 else
                    this.loadGooglePay(1);
            }
        },

        loadApplePay: function(s){

            var ths = this;
            try{
                if (window.ApplePaySession && ApplePaySession.canMakePayments() ){
                   
                } else
                    ths.showError("Unable to initialize payment"); 

            } catch (err){
                ths.showError(typeof err == 'string' ? err : (err.message || "Unable to initialize payment"));
            }
        },

        loadGooglePay: function(s){
            var ths = this;

            var billingAddress = quote.billingAddress();
            if ( quote.isVirtual() && (!billingAddress || !billingAddress.countryId) ) {
                quote.billingAddress.subscribe(function(addr) {
                    if (addr && addr.countryId) { ths.loadGooglePay(s); }
                });
                return false;
            }

            var total = parseFloat(ths.cartTotals.grand_total || 0);
            if ( !( total > 0 && billingAddress && billingAddress.countryId) ) {
                ths.showError("Unable to initialize Google Pay, please refresh your page and try again");
                return false;
            }

            let config = ths.methodsConfig.googlepay;
            
            let paymentDetails = {
                // Merchant's country (from ConfigProvider), NOT quote.billingAddress().countryId —
                // Google Pay's transactionInfo.countryCode is where the merchant trades, and
                // sending the shopper's country here is what surfaced as the wrong countryCode
                // in the gateway request.
                countryCode: config.countryCode || billingAddress.countryId,
                currencyCode: ths.currencyCode,
                totalPriceStatus: "FINAL",
                totalPrice: ths.cartTotals.grand_total.toFixed(2),
                totalPriceLabel: "Total"
            };

            try{
                let _clientRequest = {
                    environment: config.google_environment,
                    merchantInfo: {
                        merchantName: config.google_merchant_name,
                        merchantId: config.google_merchant_id,
                    },
                    emailRequired: true,
                    paymentDataCallbacks: {
                        // Same contract as the Apple Pay sheet: resolve from the REAL
                        // order-placement result (via getPlaceOrderDeferredObject), not
                        // unconditionally — and fill a guest's empty billing address from
                        // the contact Google collected, or the order dies in client-side
                        // validation with no visible feedback.
                        onPaymentAuthorized: (resp) => {
                            return new Promise(function(resolve, reject){
                                ths.token(resp.paymentMethodData.tokenizationData.token);
                                ths.cardId('new');

                                var gAddr = resp.paymentMethodData.info && resp.paymentMethodData.info.billingAddress;
                                if (gAddr) {
                                    // Google's FULL format carries one `name`; split off the
                                    // last word as the family name for Magento's two fields.
                                    var nameParts = (gAddr.name || '').trim().split(/\s+/);
                                    ths.applyWalletBillingContact({
                                        givenName: nameParts.slice(0, -1).join(' ') || nameParts[0] || '',
                                        familyName: nameParts.length > 1 ? nameParts[nameParts.length - 1] : '',
                                        addressLines: [gAddr.address1, gAddr.address2, gAddr.address3].filter(Boolean),
                                        locality: gAddr.locality,
                                        administrativeArea: gAddr.administrativeArea,
                                        postalCode: gAddr.postalCode,
                                        countryCode: gAddr.countryCode,
                                        phoneNumber: gAddr.phoneNumber
                                    });
                                }

                                var declined = function (msg) {
                                    return {
                                        transactionState: 'ERROR',
                                        error: {
                                            intent: 'PAYMENT_AUTHORIZATION',
                                            message: msg,
                                            reason: 'PAYMENT_DATA_INVALID'
                                        }
                                    };
                                };

                                ths.walletAuthorized = function (ok) {
                                    resolve(ok ? {transactionState: 'SUCCESS'}
                                               : declined($t('Payment could not be completed.')));
                                };

                                if (ths.placeOrder() === false) {
                                    ths.walletAuthorized = null;
                                    resolve(declined($t('Please complete all required checkout fields and try again.')));
                                }
                            });
                        },
                    }
                };

                let paymentsClient = new google.payments.api.PaymentsClient(_clientRequest);            
                var baseCardPaymentMethod = {
                    type: 'CARD',
                    parameters: {
                        allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
                        allowedCardNetworks: config.allowed_cards
                    }
                };

                var cardPaymentMethod = Object.assign({}, baseCardPaymentMethod, {
                    // billingAddressRequired only on the payment request, not on
                    // isReadyToPay (baseCardPaymentMethod) — it is needed to backfill a
                    // guest's empty billing address, see onPaymentAuthorized above.
                    parameters: Object.assign({}, baseCardPaymentMethod.parameters, {
                        billingAddressRequired: true,
                        billingAddressParameters: { format: 'FULL', phoneNumberRequired: false }
                    }),
                    tokenizationSpecification: {
                        type: 'PAYMENT_GATEWAY',
                        parameters: { 'gateway': config.gateway_provider, 'gatewayMerchantId': config.merchant_id },
                    }
                });

                paymentsClient.isReadyToPay(Object.assign( 
                    {}, 
                    { apiVersion: 2, apiVersionMinor: 0 }, 
                    { allowedPaymentMethods: [baseCardPaymentMethod] 
                })).then(function(response) {
                    if (response.result) {
                        const button = document.createElement('button');
                        button.type      = 'button';
                        button.className = 'opn-gpay-custom-btn';
                        button.innerHTML =
                            '<svg viewBox="0 0 48 48" height="20" width="20" xmlns="http://www.w3.org/2000/svg">' +
                                '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>' +
                                '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>' +
                                '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>' +
                                '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>' +
                            '</svg>' +
                            '<span>' + $t('Buy with Google Pay') + '</span>';
                        button.addEventListener('click', function () {
                            const _request = Object.assign({}, { apiVersion: 2, apiVersionMinor: 0 });
                            _request.allowedPaymentMethods = [cardPaymentMethod];
                            _request.transactionInfo = paymentDetails;
                            _request.merchantInfo = {
                                merchantName: config.google_merchant_name,
                                merchantId: config.google_merchant_id,
                            };
                            _request.callbackIntents = ["PAYMENT_AUTHORIZATION"];
                            _request.transactionInfo = paymentDetails;

                            paymentsClient.loadPaymentData(_request);
                        });
                        document.getElementById('gpay_button_container').innerHTML = '';
                        document.getElementById('gpay_button_container').appendChild(button);
                    }

                }).catch(function(err) {
                    ths.showError((err.statusMessage || err.message) || "Unable to initialize Google pay");
                });
            } catch(err){
                ths.showError(err.message || "Unable to initialize Google pay!");
            }
        },

        initHostedForm: function(lt){
         
            var ths = this;
            var events = {
                'hostedfield:invalid.example invalid.example': bsMarkInvalid,
                'hostedfield:valid.example valid.example': bsMarkValid,
                'hostedfield:valid.example valid.example': hideError,
            };

            function hideError($form, msg) {
                ths.showError();
            }

            function showFieldErrors(errors) {
                var msg = null;
                for (var p in errors) {
                    if( !msg )
                        msg = errors[p] || null;
                }
                if( msg );
                    ths.showError(msg);
            }

            function bsMarkInvalid(e) {
                var element = (e instanceof $.Event ? this: e);
                $(element).closest('.form-group').addClass('has-error');
            }

            function bsMarkValid(e) {
                var element = (e instanceof $.Event ? this: e);
                $(element).closest('.form-group').removeClass('has-error');
            }

            $.validator.setDefaults({
                ignore: [],
                rules: {
                    'card-details': {
                        checkValidity: true,
                        required: false
                    },
                    'card-number': {
                        checkValidity: true,
                        required: false
                    },
                    'card-expiry-date': {
                        checkValidity: true,
                        required: false
                    },
                    'card-start-date': {
                        checkValidity: true,
                        required: false
                    },
                    'card-issue-number': {
                        checkValidity: true,
                        required: false
                    },
                    'card-cvv': {
                        checkValidity: true,
                        required: false
                    }
                },
                keyup: null,
                highlight: bsMarkInvalid,
                unhighlight: bsMarkValid,
                errorPlacement: function (error, element) {
                    $(element).closest('.form-control:not(".hostedfield-element")').after(error);
                }
            });

            $.extend($.validator.messages, {
                required: "This field is required",
                creditcard: "Please enter a valid credit card number.",
            });

            var langMsgs = {
                'please_fill_out_this_field' : "This field is required",
                'a_valid_card_number_is_required' : "Credit Card is not valid",
                'a_valid_card_security_number_is_required' : "Security code is not valid",
                'a_valid_card_expiry_date_is_required' : "Expiry date is not valid",
            }; 

            $.validator.addMethod('checkValidity',
                function (value, element, params, message) {
                    element.checkValidity();
                    var valid = (element.validationMessage === '');
                    $(element).attr('aria-invalid', !valid);
                    return valid;
                },
                function (params, element) {
                    var _msg = (element.validationMessage || '').replace('.','').toLowerCase().replace(/ /g,"_");
                    return $t((langMsgs[_msg] || element.validationMessage).trim());
                }
            );

            var $form = $('.form-hostedfields'); 
            $form.on(events);
        
            try {

               if( this.cardId() == 'new' && this.method() == 'card' && this.getIntegrationType() === 'direct' ){
                    if( lt == 'A'){
                        methodLoaded['card'] = true;
                        $('#form-hf-card-number').hostedField({
                            nativeEvents: true,
                            stylesheet: $('style.hostedfields')
                        });
                        $('.form-control-hosted:input', $form).hostedField({ nativeEvents: true });
                    }
                }

                $form.validate({
                    submitHandler: function () {
                        hideError($form);

                        if( ths.getIntegrationType() != 'direct' ){
                            $('form[data-role=qidigital-form] button').trigger('click');
                            return false;
                        }

                        $form.trigger('show.loader');
                            
                        if (ths.cardId() != 'new' || ths.method() !='card' ) {
                            $('form[data-role=qidigital-form] button').trigger('click');
                            return false;
                        }
                        
                        try {
                            var $confirmButton = $('#button-confirm-qd');
                            $confirmButton.prop('disabled', true).addClass('disabled');

                            var hostedform = $form.hostedForm('instance');

                            hostedform.getPaymentDetails({}, true).then(
                                function (details) {

                                    $form.trigger('hide.loader');
                                    if (details.success) {

                                        ths.token(details.paymentToken);
                                        ths.cardId('new');
                                        $('form[data-role=qidigital-form] button').trigger('click');

                                    } else if (details.invalid) {
                                        $confirmButton.prop('disabled', false).removeClass('disabled');
                                        $form.valid();
                                        showFieldErrors(details.invalid);
                                    } else {
                                        $confirmButton.prop('disabled', false).removeClass('disabled');
                                        ths.showError(details.message || 'Unable to tokenize payment, try again');
                                    }
                                },

                                function (e) {
                                    $confirmButton.prop('disabled', false).removeClass('disabled');
                                    ths.showError(e.message || 'Unable to tokenize payment, try again');
                                }
                            );

                        } catch (e) {
                           ths.showError(e.message || 'Unable to tokenize payment, try again');
                        }
                    }
                });

            } catch (e) {
                ths.showError(e);
            }

            $('.form-hostedfields :input').on('valid', function () {
                // jQuery Validate re-checks a hosted field only on submit, so its message used to
                // stay under a card field the shopper had already corrected.
                $(this).closest('.form-group').find('label.error').hide();
                if ($(this.form).find(':invalid').length === 0) {
                    hideError($(this.form));
                }
            })
            $('.form-control-native').on('invalid', bsMarkInvalid);
            $('.form-control-native').on('valid', bsMarkValid);
             
        },
        
        initObservable: function () {
           
            this._super().observe([
                'method',
                'token',
                'saveCard',
                'cardId',  
                'ccv', 
                'ppro_option',
                'nomupay_option'
            ]);

            return this;
        },

        getCode: function () {
            return 'qidigitalpayment';
        },

        getTitle: function () {
            return this.title;
        },

        getBrowserInfo: function(){
            var screen_width = (window && window.screen ? window.screen.width : '0');
            var screen_height = (window && window.screen ? window.screen.height : '0');
            var screen_depth = (window && window.screen ? window.screen.colorDepth : '0');
            var identity = (window && window.navigator ? window.navigator.userAgent : '');
            var language = (window && window.navigator ? (window.navigator.language ? window.navigator.language : window.navigator.browserLanguage) : '');
            var timezone = (new Date()).getTimezoneOffset();
            var java = (window && window.navigator ? navigator.javaEnabled() : false);
            
            return JSON.stringify({
                deviceIdentity: identity,
                deviceTimeZone: timezone,
                deviceCapabilities: 'javascript' + (java ? ',java' : ''),
                deviceAcceptLanguage: language,
                deviceScreenResolution:  screen_width + 'x' + screen_height + 'x' + screen_depth,
            });
        },

        getData: function () {
            let _option = '';
            let _method = this.method();
            let token = this.token();

            if (_method === 'applepay' && typeof token === 'object') {
        // If it's still an object (shouldn't happen), encode it as fallback
        token = btoa(JSON.stringify(token));
    }

            if( _method == 'ppro')
                _option = this.ppro_option();
            if( _method == 'nomupay')
                _option = this.nomupay_option();
            
            return {
                method: this.item.method,
                additional_data: {
                    method: _method,
                    token: token,
                    browserInfo: this.getBrowserInfo(),
                    saveCard: this.saveCard() ? 1 : 0,
                    cardId: this.method() == 'card' ? this.cardId() : 'new',
                    ccv: this.ccv(),
                    payment_option: _option,
                }
            };
        },

        validate: function () {
            if (quote.isVirtual()) {
                var ba = quote.billingAddress();
                var hasRequired = ba &&
                    ba.countryId &&
                    ba.firstname &&
                    ba.lastname &&
                    (ba.street && ba.street.length) &&
                    ba.city &&
                    ba.postcode &&
                    ba.telephone;
                if (!hasRequired) {
                    this.showError($t('Please fill in your billing address before placing the order.'));
                    return false;
                }
            }
            var form = $('form[data-role=qidigital-form]');
            return form.validation() && form.validation('isValid');
        },

        afterPlaceOrder: function () {
            // A wallet payment has ALREADY been taken through the Direct API by the time the
            // order is placed, in every integration mode, so in Embedded mode it must go to
            // the success page like any other order and never into the embedded card form.
            // default.js reads redirectAfterPlaceOrder right after this returns.
            var isWallet = ['applepay', 'googlepay'].includes(this.method());
            if (this.getIntegrationType() !== 'embedded' || isWallet) {
                this.redirectAfterPlaceOrder = true;
                this._super();
                return;
            }
            this.loadEmbeddedForm();
        },

        loadEmbeddedForm: function () {
            var ths = this;
            var container = document.getElementById('opn-embedded-form-container');

            // Hidden inputs for the signed request; nested arrays become name[key] fields,
            // the same shape Helper\Data::renderForm() emits server-side.
            function appendFields(form, value, name) {
                if (value !== null && typeof value === 'object') {
                    Object.keys(value).forEach(function (k) {
                        appendFields(form, value[k], name ? name + '[' + k + ']' : k);
                    });
                    return;
                }
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = name;
                input.value = (value === null || value === undefined) ? '' : String(value);
                form.appendChild(input);
            }
            if (!container) {
                window.location.href = window.checkoutConfig.defaultSuccessPageUrl || '/checkout/onepage/success/';
                return;
            }

            // Move container to body as a full-viewport overlay (it was inside .opc-wrapper)
            document.body.appendChild(container);
            container.style.cssText = 'display:block;position:fixed;top:0;left:0;width:100%;height:100%;' +
                'background:#fff;z-index:9999;overflow-y:auto;box-sizing:border-box;';

            container.innerHTML =
                '<style>' +
                '#opn-pay-loading{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);' +
                'font-size:15px;color:#888;font-family:sans-serif;}' +
                '#opn-pay-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;' +
                'padding:20px;box-sizing:border-box;}' +
                '#opn-pay-inner{max-width:680px;width:100%;}' +
                '#opn-pay-cancel{display:none;text-align:center;margin-top:14px;font-size:12px;color:#aaa;' +
                'text-decoration:none;cursor:pointer;font-family:sans-serif;}' +
                '#opn-pay-cancel:hover{color:#555;}' +
                '</style>' +
                '<div id="opn-pay-loading">' + $t('Loading…') + '</div>' +
                '<div id="opn-pay-wrap"><div id="opn-pay-inner">' +
                '<div id="opn-embedded-iframe-host"></div>' +
                '<a id="opn-pay-cancel" href="javascript:void(0)">' + $t('← Cancel and return to cart') + '</a>' +
                '</div></div>';

            fetch(config.embedded_data_url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    container.style.display = 'none';
                    ths.showError(data.error || $t('Unable to load payment form.'));
                    return;
                }

                // The order already exists by the time this form shows, so merely hiding the
                // form left a checkout page with no quote behind it. Leave through the store,
                // which puts the basket back (Controller/Payment/Basket).
                document.getElementById('opn-pay-cancel').href = config.basket_url;

                // The Hosted Payment Form is POSTed into an iframe on THIS page. The hostedForms
                // SDK has no inline mode: with modal:false it merely auto-submits a plain form,
                // which navigates the whole browser to the gateway (reported 21/09/2026).
                var host = document.getElementById('opn-embedded-iframe-host');
                var formReady = function () {
                    var loading = document.getElementById('opn-pay-loading');
                    var cancel  = document.getElementById('opn-pay-cancel');
                    if (loading) { loading.style.display = 'none'; }
                    if (cancel)  { cancel.style.display = 'block'; }
                };

                var frame = document.createElement('iframe');
                frame.name  = 'opn-embedded-frame';
                frame.title = $t('Secure payment form');
                // Apple Pay inside a cross-origin iframe needs the payment permission delegated.
                frame.setAttribute('allow', 'payment *');
                frame.style.cssText = 'width:100%;min-height:760px;border:0;';
                frame.addEventListener('load', formReady);
                setTimeout(formReady, 8000);
                host.appendChild(frame);

                try {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = data.gateway_url;
                    form.target = frame.name;
                    form.style.display = 'none';
                    appendFields(form, data.request, '');
                    host.appendChild(form);
                    form.submit();
                } catch (e) {
                    container.style.display = 'none';
                    ths.showError($t('Unable to initialize payment form.'));
                }
            })
            .catch(function () {
                container.style.display = 'none';
                ths.showError($t('Unable to connect to payment service.'));
            });
        },

    });
});
