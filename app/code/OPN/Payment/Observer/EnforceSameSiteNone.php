<?php
namespace OPN\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class EnforceSameSiteNone implements ObserverInterface
{
    private WriterInterface $configWriter;
    private ScopeConfigInterface $scopeConfig;
    private ?LoggerInterface $logger;

    public function __construct(
        WriterInterface $configWriter,
        ScopeConfigInterface $scopeConfig,
        ?LoggerInterface $logger = null
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $currentValue = $this->scopeConfig->getValue('web/cookie/samesite');

        if ($currentValue !== 'None') {
            $this->configWriter->save('web/cookie/samesite', 'None', 'default', 0);

            if ($this->logger) {
                $this->logger->info('OPN: Auto-corrected web/cookie/samesite to None');
            }
        }
    }
}
