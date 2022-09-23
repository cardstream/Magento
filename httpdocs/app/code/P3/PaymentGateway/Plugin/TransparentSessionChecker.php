<?php

namespace P3\PaymentGateway\Plugin;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Intended to preserve session cookie after submitting POST form from gateway to Magento controller.
 */
class TransparentSessionChecker
{
    const TRANSPARENT_REDIRECT_PATH = '/paymentgateway/order/process/';

    /**
     * @var Http
     */
    private $request;

    /**
     * @param Http $request
     */
    public function __construct(Http $request) {
        $this->request = $request;
    }

    /**
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if ($result === false) {
            return false;
        }

        if (
            strpos((string)$this->request->getPathInfo(), self::TRANSPARENT_REDIRECT_PATH) !== false
            && isset($_REQUEST['customerPHPSESSID'])
        ) {
            $_SESSION['new_session_id'] = $_REQUEST['customerPHPSESSID'];
        }

        return true;
    }
}
