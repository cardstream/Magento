<?php
namespace OPN\Payment\Plugin\Session;

use Magento\Framework\Session\Config\ConfigInterface;
use Magento\Framework\HTTP\Header;
use OPN\Payment\Validator\SameSite;

class ModifySameSite
{
    /**
     * @var Header
     */
    private $header;
    
    /**
     * @var SameSite
     */
    private $validator;

    public function __construct(
        Header $header,
        SameSite $validator
    ) {
        $this->header = $header;
        $this->validator = $validator;
    }

    /**
     * Force SameSite=None; Secure for session cookies when browser is compatible
     */
    public function beforeSetCookie(
        ConfigInterface $subject,
        $name,
        $value,
        $metadata = null
    ) {
        if ($metadata) {
            $agent = $this->header->getHttpUserAgent();
            $shouldSetSameSiteNone = $this->validator->shouldSendSameSiteNone($agent);
            
            if ($shouldSetSameSiteNone) {
                $metadata->setSameSite('None');
                $metadata->setSecure(true);
            } else {
                // For incompatible browsers, use Lax
                $metadata->setSameSite('Lax');
                $metadata->setSecure(true);
            }
        }

        return [$name, $value, $metadata];
    }
}