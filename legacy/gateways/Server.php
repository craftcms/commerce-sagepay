<?php

namespace craft\commerce\sagepay\gateways;

// Gateways saved with Sage Pay for Craft Commerce 5 store this class name as their type.
/** @deprecated in 6.0.0. Use {@see \CraftCms\CommerceSagePay\Gateway} instead. */
class_alias(\CraftCms\CommerceSagePay\Gateway::class, 'craft\commerce\sagepay\gateways\Server');

/** @phpstan-ignore-next-line */
if (false) {
    class Server extends \CraftCms\CommerceSagePay\Gateway
    {
    }
}
