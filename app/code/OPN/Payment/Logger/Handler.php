<?php

namespace OPN\Payment\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

/**
 * Writes the module's debug log to its own file rather than var/log/system.log.
 *
 * Replaces the direct \Zend_Log / \Zend_Log_Writer_Stream usage that used to live in
 * Helper\Data::log(). Zend Framework 1 was removed from Magento in 2.4.6, so that code
 * raised "Class \"Zend_Log\" not found" on every gateway request once Debug was enabled.
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/open_payment_network.log';

    /**
     * @var int
     */
    protected $loggerType = MonologLogger::INFO;
}
