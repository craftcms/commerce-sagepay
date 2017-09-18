<?php

namespace craft\commerce\sagepay;

use craft\commerce\sagepay\gateways\SagePayServer;
use craft\commerce\sagepay\gateways\SagePayDirect;
use craft\commerce\services\Gateways;
use craft\events\RegisterComponentTypesEvent;
use yii\base\Event;


/**
 * Plugin represents the Stripe integration plugin.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  1.0
 */
class Plugin extends \craft\base\Plugin
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(Gateways::class, Gateways::EVENT_REGISTER_GATEWAY_TYPES,  function(RegisterComponentTypesEvent $event) {
            $event->types[] = SagePayDirect::class;
            $event->types[] = SagePayServer::class;
        });
    }
}
