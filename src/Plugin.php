<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;

/**
 * Sage Pay for Craft Commerce.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '2.0.0';

    public string $minVersionRequired = '5.1.0';

    public function boot(GatewayTypes $gatewayTypes): void
    {
        $gatewayTypes->register(Gateway::class);
    }
}
