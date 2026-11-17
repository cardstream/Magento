<?php
namespace OPN\Payment\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\Cookie\SensitiveCookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Store\Model\ScopeInterface;
use OPN\Payment\Validator\SameSite;

class SwitchSameSite
{
    public const CONFIG_PATH = 'web/cookie/samesite';
    public const CONFIG_AFFECTED_KEYS = 'web/cookie/affected_keys';
    
    /**
     * @var SameSite
     */
    private $validator;
    
    /**
     * @var Header
     */
    private $header;
    
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var array
     */
    private $affectedKeys = [];

    public function __construct(
        Header $header,
        ScopeConfigInterface $scopeConfig,
        SameSite $validator
    ) {
        $this->validator = $validator;
        $this->header = $header;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Modify same site cookie attribute for public cookies
     */
    public function beforeSetPublicCookie(
        PhpCookieManager $subject,
        $name,
        $value,
        ?PublicCookieMetadata $metadata = null
    ) {
        if ($this->shouldModifyCookie($name)) {
            $this->applySameSiteSettings($metadata);
        }

        return [$name, $value, $metadata];
    }

    /**
     * Modify same site cookie attribute for sensitive cookies (like PHPSESSID)
     */
    public function beforeSetSensitiveCookie(
        PhpCookieManager $subject,
        $name,
        $value,
        ?SensitiveCookieMetadata $metadata = null
    ) {
        if ($this->shouldModifyCookie($name)) {
            $this->applySameSiteSettings($metadata);
        }

        return [$name, $value, $metadata];
    }

    /**
     * Check if we should modify this cookie
     */
    private function shouldModifyCookie($name)
    {
        // Always modify session cookie
        if ($name === 'PHPSESSID') {
            return true;
        }
        
        // Check affected keys for other cookies
        return $this->isAffectedKeys($name);
    }

    /**
     * Apply SameSite settings to cookie metadata
     */
    private function applySameSiteSettings($metadata)
{
    if (!$metadata) {
        return;
    }

    $agent = $this->header->getHttpUserAgent();
    $sameSite = $this->validator->shouldSendSameSiteNone($agent);

    // Only PublicCookieMetadata supports setSecure() and setSameSite()
    $isPublic = $metadata instanceof PublicCookieMetadata;

    if ($sameSite === false) {
        if ($isPublic) {
            $metadata->setSecure(true);
        }
        // Do NOT set SameSite for incompatible browsers
    } else {
        if ($isPublic) {
            $metadata->setSecure(true);
            $metadata->setSameSite('None');
        }
    }
}


    /**
     * Check given key is in affected keys list
     */
    private function isAffectedKeys($name)
    {
        if (!count($this->affectedKeys)) {
            $affectedKeys = $this->scopeConfig->getValue(self::CONFIG_AFFECTED_KEYS, ScopeInterface::SCOPE_STORE);
            $this->affectedKeys = explode(',', strtolower((string)$affectedKeys));
        }

        return in_array(strtolower($name), $this->affectedKeys);
    }
}