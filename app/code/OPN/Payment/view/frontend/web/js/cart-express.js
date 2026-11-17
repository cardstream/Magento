/**
 * OPN Payment — Cart / Mini-cart Express Checkout
 *
 * Initialises Apple Pay and Google Pay "Buy now" buttons directly on the cart
 * page and the mini-cart, opening the native wallet sheet without navigating
 * to the checkout page first.
 *
 * Config is injected server-side by Block\Shortcut\Buttons and passed as the
 * second argument when this module is required via data-mage-init or inline
 * require().
 */
define(['jquery', 'mage/translate'], function ($) {
    'use strict';

    /**
     * @param {HTMLElement} container  – the .opn-express-shortcut element
     * @param {Object}      config     – server-side config from Buttons block
     */
    return function OPNCartExpress(container, config) {

        var $c       = $(container);
        var cfg      = config || {};
        var urls     = cfg.urls || {};
        var gpayCfg  = cfg.googlepay || {};
        var apayCfg  = cfg.applepay  || {};
        var currency = cfg.currency  || 'EUR';

        /* ------------------------------------------------------------------ */
        /* Helpers                                                              */
        /* ------------------------------------------------------------------ */

        function getBrowserInfo() {
            return {
                deviceChannel:      'browser',
                browserAcceptHeader: navigator.mimeTypes.length.toString(),
                browserJavaEnabled:  navigator.javaEnabled(),
                browserJavascriptEnabled: true,
                browserLanguage:     navigator.language,
                browserColorDepth:   screen.colorDepth,
                browserScreenHeight: screen.height,
                browserScreenWidth:  screen.width,
                browserTZ:           new Date().getTimezoneOffset(),
                browserUserAgent:    navigator.userAgent
            };
        }

        function normaliseWalletAddress(raw, forApple) {
            var addr = {};
            if (forApple) {
                var lines = raw.addressLines || [];
                addr.firstname   = raw.givenName  || '';
                addr.lastname    = raw.familyName  || '';
                addr.street      = [lines[0] || '', lines[1] || ''];
                addr.city        = raw.locality             || '';
                addr.region_id   = raw.administrativeArea   || '';
                addr.postcode    = raw.postalCode            || '';
                addr.country_id  = (raw.countryCode || '').toUpperCase();
                addr.telephone   = raw.phoneNumber           || '0000000000';
                addr.email       = raw.emailAddress          || '';
            } else {
                // Google Pay shippingAddress / billingAddress
                var name = (raw.name || '').trim().split(' ');
                addr.firstname   = name[0] || '';
                addr.lastname    = name.slice(1).join(' ') || name[0] || '';
                addr.street      = [raw.address1 || '', raw.address2 || ''];
                addr.city        = raw.locality             || '';
                addr.region_id   = raw.administrativeArea   || '';
                addr.postcode    = raw.postalCode            || '';
                addr.country_id  = (raw.countryCode || '').toUpperCase();
                addr.telephone   = raw.phoneNumber           || '0000000000';
            }
            return addr;
        }

        function post(url, data) {
            return $.ajax({ url: url, method: 'POST', dataType: 'json', data: data });
        }

        function showError(msg) {
            var $err = $c.find('.opn-express-error');
            if (!$err.length) {
                $err = $('<div class="opn-express-error" style="color:#d0021b;margin:6px 0;font-size:13px;"></div>');
                $c.prepend($err);
            }
            $err.text(msg || '').toggle(!!msg);
        }

        /* ------------------------------------------------------------------ */
        /* Apple Pay                                                            */
        /* ------------------------------------------------------------------ */

        function initApplePay() {
            if (!window.ApplePaySession || !ApplePaySession.canMakePayments()) {
                $c.find('.opn-ap-btn').hide();
                return;
            }
            $c.find('.opn-ap-btn').show().on('click', startApplePay);
        }

        function startApplePay() {
            showError('');

            var request = $.extend(true, {}, apayCfg.request || {});
            request.total = request.total || {};
            request.total.type = 'pending';   // the real amount arrives with get_totals, before the sheet can pay
            request.currencyCode = request.currencyCode || currency;

            request.requiredShippingContactFields = ['email', 'name', 'phone', 'postalAddress'];
            request.requiredBillingContactFields  = ['name', 'postalAddress'];
            request.shippingType = 'shipping';

            var appleShipping = {};
            var appleSelected = null;   // tracked in onshippingmethodselected: Apple's payment object has no shippingMethod

            // Safari only opens a session created synchronously in the click handler. It used to be
            // created after get_totals answered, so a slow answer made the click do nothing and the
            // shopper had to click several times. The total now loads alongside merchant validation.
            var session;
            try {
                session = new ApplePaySession(3, request);
            } catch (e) {
                showError(e.message || 'Unable to start Apple Pay');
                return;
            }

            var totals = post(urls.get_totals, {action: 'get_totals'}).then(function (resp) {
                if (!resp.success) return $.Deferred().reject(new Error(resp.message || 'Unable to load cart total'));
                request.total = { label: request.total.label || 'Total', amount: parseFloat(resp.data.total || 0).toFixed(2) };
            });

            session.onvalidatemerchant = function (event) {
                // Every handler below reads request.total, so the sheet is not handed back to
                // the shopper until the cart total has arrived.
                $.when(totals, $.ajax({
                    url:      urls.verify_merchant + '?validation_url=' + encodeURIComponent(event.validationURL),
                    method:   'GET',
                    dataType: 'json'
                })).then(function (_, verify) {
                    var resp = verify[0];
                    if (resp && resp.session)
                        session.completeMerchantValidation(resp.session);
                    else
                        session.abort(), showError((resp && resp.message) || 'Apple Pay merchant validation failed');
                }, function (err) {
                    session.abort();
                    showError((err && err.message) || 'Apple Pay merchant validation failed');
                });
            };

            session.onshippingcontactselected = function (event) {
                var addr = normaliseWalletAddress(event.shippingContact, true);
                post(urls.get_shippings, {action: 'get_shippings', address: addr}).then(function (resp) {
                    if (!resp.success) {
                        session.completeShippingContactSelection({
                            errors:          [new ApplePayError('shippingContactInvalid', 'country', resp.message)],
                            newTotal:        request.total,
                            newShippingMethods: []
                        });
                        return;
                    }
                    appleShipping = resp.data || {};
                    var methods = Object.values(appleShipping).map(function (r) {
                        return { identifier: r.id, label: r.label, detail: r.description || '', amount: r.price };
                    });
                    var firstRate = methods[0] || null;
                    appleSelected = firstRate ? firstRate.identifier : null;
                    var newTotal  = parseFloat(request.total.amount) + parseFloat(firstRate ? firstRate.amount : 0);
                    session.completeShippingContactSelection({
                        newShippingMethods: methods,
                        newTotal:           { label: request.total.label || 'Total', amount: newTotal.toFixed(2) },
                        newLineItems:       []
                    });
                }, function () {
                    session.completeShippingContactSelection({ errors: [], newTotal: request.total, newShippingMethods: [] });
                });
            };

            session.onshippingmethodselected = function (event) {
                appleSelected = (event.shippingMethod || {}).identifier || appleSelected;
                var shipping = parseFloat((event.shippingMethod || {}).amount || 0);
                var base     = parseFloat(request.total.amount || 0);
                // Subtract previously added shipping to get base, then add new
                session.completeShippingMethodSelection({
                    newTotal: { label: request.total.label || 'Total', amount: (base + shipping).toFixed(2) }
                });
            };

            session.onpaymentauthorized = function (event) {
                var payment   = event.payment;
                var token     = btoa(JSON.stringify(payment.token.paymentData));
                var shipping  = normaliseWalletAddress(payment.shippingContact || {}, true);
                var billing   = normaliseWalletAddress(payment.billingContact  || {}, true);
                var email     = (payment.shippingContact || {}).emailAddress || '';
                // The method the shopper picked in the sheet. This used to read
                // event.payment.shippingMethod, which does not exist in Apple's API, and fell
                // back to the FIRST rate, silently ignoring the shopper's choice.
                var chosenId  = appleSelected || Object.keys(appleShipping)[0] || '';

                post(urls.place_order, {
                    action:          'place_order',
                    method:          'applepay',
                    token:           token,
                    email:           email,
                    billing:         billing,
                    shipping:        shipping,
                    shipping_method: chosenId,
                    browserInfo:     JSON.stringify(getBrowserInfo())
                }).then(function (resp) {
                    if (resp.success) {
                        session.completePayment({ status: ApplePaySession.STATUS_SUCCESS });
                        setTimeout(function () { window.location.replace(resp.redirect); }, 50);
                    } else {
                        session.completePayment({ status: ApplePaySession.STATUS_FAILURE });
                        showError(resp.message || 'Unable to process your payment');
                    }
                }, function () {
                    session.completePayment({ status: ApplePaySession.STATUS_FAILURE });
                    showError('Unable to process your payment');
                });
            };

            session.begin();
        }

        /* ------------------------------------------------------------------ */
        /* Google Pay                                                           */
        /* ------------------------------------------------------------------ */

        function initGooglePay() {
            var containerId = 'opn-gpay-' + Math.random().toString(36).slice(2, 8);
            $c.find('.opn-gp-container').attr('id', containerId);

            var baseCard = {
                type:       'CARD',
                parameters: {
                    allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                    allowedCardNetworks: gpayCfg.allowed_cards || ['VISA', 'MASTERCARD']
                }
            };

            var tokenisedCard = $.extend(true, {}, baseCard, {
                tokenizationSpecification: {
                    type:       'PAYMENT_GATEWAY',
                    parameters: {
                        gateway:           gpayCfg.gateway_provider  || 'cardstream',
                        gatewayMerchantId: gpayCfg.merchant_id        || ''
                    }
                }
            });

            var googleShipping = {};

            var client = new google.payments.api.PaymentsClient({
                environment:  gpayCfg.google_environment || 'TEST',
                merchantInfo: {
                    merchantName: gpayCfg.google_merchant_name || '',
                    merchantId:   gpayCfg.google_merchant_id   || ''
                },
                paymentDataCallbacks: {
                    onPaymentAuthorized: function (resp) {
                        return new Promise(function (resolve) {
                            var token    = resp.paymentMethodData.tokenizationData.token;
                            var shipping = normaliseWalletAddress(resp.shippingAddress || {}, false);
                            var billing  = normaliseWalletAddress(
                                (resp.paymentMethodData.info && resp.paymentMethodData.info.billingAddress) || resp.shippingAddress || {},
                                false
                            );
                            var email    = resp.email || '';
                            var chosenId = (resp.shippingOptionData && resp.shippingOptionData.id) || '';

                            post(urls.place_order, {
                                action:          'place_order',
                                method:          'googlepay',
                                token:           token,
                                email:           email,
                                billing:         billing,
                                shipping:        shipping,
                                shipping_method: chosenId,
                                browserInfo:     JSON.stringify(getBrowserInfo())
                            }).then(function (placeResp) {
                                if (placeResp.success) {
                                    resolve({ transactionState: 'SUCCESS' });
                                    setTimeout(function () { window.location.replace(placeResp.redirect); }, 50);
                                } else {
                                    resolve({
                                        transactionState: 'ERROR',
                                        error: { reason: 'PAYMENT_AUTHORIZATION', message: placeResp.message || 'Payment failed', intent: 'PAYMENT_AUTHORIZATION' }
                                    });
                                    showError(placeResp.message || 'Unable to process your payment');
                                }
                            }, function () {
                                resolve({ transactionState: 'ERROR', error: { reason: 'PAYMENT_AUTHORIZATION', message: 'Server error', intent: 'PAYMENT_AUTHORIZATION' } });
                                showError('Unable to process your payment');
                            });
                        });
                    },
                    onPaymentDataChanged: function (resp) {
                        return new Promise(function (resolve) {
                            var update  = {};
                            var trigger = resp.callbackTrigger;

                            if (trigger === 'INITIALIZE' || trigger === 'SHIPPING_ADDRESS') {
                                // Fetch cart totals and shipping rates for the given address.
                                // Google Pay provides a pre-selected address on INITIALIZE too.
                                var addr = normaliseWalletAddress(resp.shippingAddress || {}, false);

                                post(urls.get_totals, { action: 'get_totals' }).then(function (totResp) {
                                    var base  = totResp.success ? parseFloat(totResp.data.total || 0) : 0;
                                    var cur   = totResp.success ? (totResp.data.currency || currency) : currency;

                                    if (totResp.success) {
                                        update.newTransactionInfo = buildTransactionInfo(base.toFixed(2), cur);
                                        update.newTransactionInfo.displayItems = [
                                            { label: 'Subtotal', type: 'SUBTOTAL', price: totResp.data.subtotal, status: 'FINAL' },
                                            { label: 'Tax',      type: 'TAX',      price: totResp.data.tax,      status: 'FINAL' }
                                        ];
                                    }

                                    if (!addr.country_id) {
                                        // No address yet — return totals without shipping
                                        resolve(update);
                                        return;
                                    }

                                    post(urls.get_shippings, { action: 'get_shippings', address: addr }).then(function (shpResp) {
                                        if (!shpResp.success) {
                                            update.error = { reason: 'SHIPPING_ADDRESS_UNSERVICEABLE', message: shpResp.message, intent: 'SHIPPING_ADDRESS' };
                                            resolve(update);
                                            return;
                                        }
                                        googleShipping = shpResp.data || {};
                                        var options   = Object.values(googleShipping).map(function (r) {
                                            return { id: r.id, label: r.label, description: r.description || '', price: r.price };
                                        });
                                        update.newShippingOptionParameters = {
                                            shippingOptions:         options,
                                            defaultSelectedOptionId: shpResp.selectedId || (options[0] && options[0].id) || ''
                                        };
                                        var firstCost = parseFloat((options[0] && options[0].price) || 0);
                                        update.newTransactionInfo = buildTransactionInfo((base + firstCost).toFixed(2), cur);
                                        resolve(update);
                                    }, function () { resolve(update); });

                                }, function () { resolve({}); });

                            } else if (trigger === 'SHIPPING_OPTION') {
                                var optId   = resp.shippingOptionData && resp.shippingOptionData.id;
                                var optRate = googleShipping[optId] || Object.values(googleShipping)[0] || {};
                                var shpCost = parseFloat(optRate.price || 0);
                                post(urls.get_totals, { action: 'get_totals' }).then(function (totResp) {
                                    var base = totResp.success ? parseFloat(totResp.data.total || 0) : 0;
                                    var cur  = totResp.success ? (totResp.data.currency || currency) : currency;
                                    resolve({ newTransactionInfo: buildTransactionInfo((base + shpCost).toFixed(2), cur) });
                                }, function () { resolve({}); });
                            } else {
                                resolve({});
                            }
                        });
                    }
                }
            });

            client.isReadyToPay({
                apiVersion:           2,
                apiVersionMinor:      0,
                allowedPaymentMethods: [baseCard]
            }).then(function (response) {
                if (!response.result) return;

                function onGooglePayClick() {
                    showError('');
                    post(urls.get_totals, { action: 'get_totals' }).then(function (totResp) {
                        var total = totResp.success ? parseFloat(totResp.data.total || 0).toFixed(2) : '0.00';
                        var cur   = totResp.success ? (totResp.data.currency || currency) : currency;

                        var payRequest = {
                            apiVersion:            2,
                            apiVersionMinor:       0,
                            allowedPaymentMethods: [tokenisedCard],
                            transactionInfo:       buildTransactionInfo(total, cur),
                            merchantInfo: {
                                merchantName: gpayCfg.google_merchant_name || '',
                                merchantId:   gpayCfg.google_merchant_id   || ''
                            },
                            callbackIntents:         ['PAYMENT_AUTHORIZATION', 'SHIPPING_ADDRESS', 'SHIPPING_OPTION'],
                            shippingAddressRequired: true,
                            shippingAddressParameters: { phoneNumberRequired: true },
                            emailRequired:           true,
                            shippingOptionRequired:  true
                        };

                        client.loadPaymentData(payRequest);
                    });
                }

                // Custom Google Pay button (replaces the SDK button to control hover)
                var btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'opn-express-btn opn-express-btn--googlepay';
                btn.innerHTML =
                    '<svg viewBox="0 0 48 48" height="20" width="20" xmlns="http://www.w3.org/2000/svg" style="margin-right:6px">' +
                        '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>' +
                        '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>' +
                        '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>' +
                        '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>' +
                    '</svg>' +
                    '<span>' + $.mage.__('Buy with Google Pay') + '</span>';
                btn.addEventListener('click', onGooglePayClick);

                var slot = document.getElementById(containerId);
                if (slot) {
                    slot.innerHTML = '';
                    slot.appendChild(btn);
                }
            }).catch(function (err) {
                $c.find('.opn-gp-container').hide();
            });
        }

        function buildTransactionInfo(total, cur) {
            var info = {
                currencyCode:      cur || currency,
                totalPriceStatus:  'FINAL',
                totalPrice:        parseFloat(total || 0).toFixed(2),
                totalPriceLabel:   'Total'
            };
            // Google Pay requires transactionInfo.countryCode once callbackIntents are in
            // play (they are — SHIPPING_ADDRESS/SHIPPING_OPTION below). It is the MERCHANT's
            // country, supplied by Block\Shortcut\Buttons::getExpressConfig().
            if (cfg.country_code) {
                info.countryCode = cfg.country_code;
            }
            return info;
        }

        /* ------------------------------------------------------------------ */
        /* Boot — load SDKs then initialise buttons                            */
        /* ------------------------------------------------------------------ */

        function loadScript(src, cb) {
            var s = document.createElement('script');
            s.src   = src;
            s.async = true;
            s.onload = cb;
            s.onerror = function () {};
            document.head.appendChild(s);
        }

        if (cfg.show_applepay) {
            if (window.ApplePaySession) {
                initApplePay();
            } else {
                loadScript('https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js', function () {
                    initApplePay();
                });
            }
        }

        if (cfg.show_googlepay) {
            if (window.google && window.google.payments) {
                initGooglePay();
            } else {
                loadScript('https://pay.google.com/gp/p/js/pay.js', function () {
                    initGooglePay();
                });
            }
        }
    };
});
