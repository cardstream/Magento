define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'qidigitalpayment',
                component: 'OPN_Payment/js/view/payment/method-renderer/opn'
            }
        );
        return Component.extend({});
    }
);