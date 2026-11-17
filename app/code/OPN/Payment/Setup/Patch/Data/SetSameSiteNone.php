<?php
namespace OPN\Payment\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class SetSameSiteNone implements DataPatchInterface
{
    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;

    public function __construct(WriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    public function apply()
    {
        // Force the admin configuration value for SameSite cookie
        $this->configWriter->save(
            'web/cookie/samesite',  // Path in core_config_data
            'None',                 // The desired value
            'default',              // Scope (global)
            0                       // Scope ID
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
