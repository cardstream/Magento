/**
 * Browser-driven 3-D Secure 2.x state machine, shared by every integration type
 * (card/direct and wallet). The server never renders 3DS HTML itself — it only
 * ever returns { status: 'challenge'|'success'|'declined', ... } JSON via
 * opn/order/continue3ds. This module owns rendering the hidden "method" iframe
 * and the visible challenge iframe, relaying the ACS's postMessage result, and
 * looping through as many challenge rounds as the gateway asks for.
 */
(function () {
    'use strict';

    var METHOD_TIMEOUT_MS = 10000;

    // How long a declined shopper reads the reason before being moved on. The
    // destination re-states it (the cart via Magento's message queue, the order status
    // page from the stored response), so this is courtesy, not the only notice.
    var DECLINE_REDIRECT_MS = 2000;

    function buildAutoSubmitForm(url, fields, targetName) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.target = targetName;
        form.style.display = 'none';

        function addField(name, value) {
            if (value !== null && typeof value === 'object') {
                Object.keys(value).forEach(function (k) {
                    addField(name + '[' + k + ']', value[k]);
                });
                return;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value === undefined || value === null ? '' : value;
            form.appendChild(input);
        }

        Object.keys(fields || {}).forEach(function (k) { addField(k, fields[k]); });

        return form;
    }

    function OPN3DS(config) {
        this.orderId = config.orderId;
        this.continueUrl = config.continueUrl;
        this.origin = config.origin;
        this.container = config.container;
        this.onError = config.onError || function (msg) { alert(msg); };
        this.settled = false;
        this._messageHandler = null;
    }

    OPN3DS.prototype.start = function (threeDSURL, threeDSRequest, threeDSRef) {
        this.settled = false;
        this._cleanup();

        var isMethod = threeDSRequest && Object.prototype.hasOwnProperty.call(threeDSRequest, 'threeDSMethodData');

        if (isMethod) {
            this._runMethodStep(threeDSURL, threeDSRequest, threeDSRef);
        } else {
            this._runChallengeStep(threeDSURL, threeDSRequest, threeDSRef);
        }
    };

    OPN3DS.prototype._runMethodStep = function (url, request, ref) {
        var self = this;
        var frameName = 'opn3ds_method_' + Date.now();

        var iframe = document.createElement('iframe');
        iframe.name = frameName;
        iframe.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;border:0;';
        document.body.appendChild(iframe);
        this._iframe = iframe;

        var form = buildAutoSubmitForm(url, request, frameName);
        document.body.appendChild(form);
        this._form = form;
        form.submit();

        this._listenForCallback(ref);

        this._timeoutId = setTimeout(function () {
            if (self.settled) { return; }
            self._continue({ threeDSMethodData: 'timeout' }, ref);
        }, METHOD_TIMEOUT_MS);
    };

    OPN3DS.prototype._runChallengeStep = function (url, request, ref) {
        var frameName = 'opn3ds_challenge_' + Date.now();

        if (this.container) {
            this.container.innerHTML = '';
            var iframe = document.createElement('iframe');
            iframe.name = frameName;
            iframe.width = '500';
            iframe.height = '600';
            iframe.style.cssText = 'border:0;max-width:100%;';
            this.container.appendChild(iframe);
            this._iframe = iframe;
        }

        var form = buildAutoSubmitForm(url, request, frameName);
        document.body.appendChild(form);
        this._form = form;
        form.submit();

        this._listenForCallback(ref);
        // No timeout here — a visible challenge waits on the customer.
    };

    OPN3DS.prototype._listenForCallback = function (ref) {
        var self = this;
        this._messageHandler = function (event) {
            if (event.origin !== self.origin) { return; }
            if (!event.data || event.data.source !== 'opn3ds') { return; }
            if (self.settled) { return; }
            self._continue(event.data.payload || {}, ref);
        };
        window.addEventListener('message', this._messageHandler);
    };

    OPN3DS.prototype._continue = function (payload, ref) {
        if (this.settled) { return; }
        this.settled = true;
        this._cleanup();

        var self = this;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', this.continueUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                self.onError('Unable to process your request, try again');
                return;
            }
            self._handleResult(data);
        };
        xhr.onerror = function () {
            self.onError('Unable to connect to payment service.');
        };
        xhr.send(
            'id=' + encodeURIComponent(this.orderId) +
            '&threeDSRef=' + encodeURIComponent(ref) +
            '&threeDSResponse=' + encodeURIComponent(JSON.stringify(payload))
        );
    };

    OPN3DS.prototype._handleResult = function (data) {
        if (data.status === 'success') {
            window.location.replace(data.redirect);
        } else if (data.status === 'challenge') {
            this.start(data.threeDSURL, data.threeDSRequest, data.threeDSRef);
        } else {
            this.onError(data.message || 'Payment could not be completed.');

            // A decline used to end here: the message was painted onto the bare 3DS host
            // page and nothing else happened, so the shopper was stranded. Continue3ds
            // now returns somewhere they can retry — honour it.
            if (data.redirect) {
                window.setTimeout(function () {
                    window.location.replace(data.redirect);
                }, DECLINE_REDIRECT_MS);
            }
        }
    };

    OPN3DS.prototype._cleanup = function () {
        if (this._timeoutId) { clearTimeout(this._timeoutId); this._timeoutId = null; }
        if (this._messageHandler) { window.removeEventListener('message', this._messageHandler); this._messageHandler = null; }
        if (this._form && this._form.parentNode) { this._form.parentNode.removeChild(this._form); }
        this._form = null;
        if (this._iframe && !this.container && this._iframe.parentNode) {
            this._iframe.parentNode.removeChild(this._iframe);
        }
        this._iframe = null;
    };

    window.OPN3DS = OPN3DS;
})();
