<?php
namespace OPN\Payment\Controller\Order;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Lands the ACS's POST-back for both the silent 3DS "method" step and a visible
 * challenge. Deliberately does nothing but relay the POST data to the parent
 * window via postMessage — no order lookup, no gateway call, no order
 * finalisation. The browser (view/frontend/web/js/threeds.js) owns the 3DS
 * state machine; Controller/Order/Continue3ds.php is the only place that talks
 * to the gateway, using a fresh server-to-server call whose response is what's
 * actually trusted (matching the existing continuation pattern in
 * Controller/Redirect/Index.php — see its "carry NO gateway signature" note).
 */
class Threedscallback extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $payload = $this->getRequest()->getParams();
        unset($payload['payment_id']);

        $origin = rtrim($this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $originJson = json_encode($origin, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $this->getResponse()->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $this->getResponse()->setBody(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><script>' .
            'if (window.parent && window.parent !== window) {' .
            'window.parent.postMessage({ source: "opn3ds", payload: ' . $json . ' }, ' . $originJson . ');' .
            '}' .
            '</script></body></html>'
        );

        return $this->getResponse();
    }
}
