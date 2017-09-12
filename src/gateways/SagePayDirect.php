<?php

namespace craft\commerce\sagepay\gateways;

use Craft;
use craft\commerce\omnipay\base\CreditCardGateway;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\SagePay\DirectGateway as Gateway;

/**
 * PayPalPro represents PayPalPro gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class SagePayDirect extends CreditCardGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $vendor;

    /**
     * @var bool
     */
    public $testMode = false;

    /**
     * @var string
     */
    public $referrerId;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'SagePay Direct');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-sagepay/gatewaySettings', ['gateway' => $this]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var Gateway $gateway */
        $gateway = Omnipay::create($this->getGatewayClassName());

        $gateway->setVendor($this->vendor);
        $gateway->setTestMode($this->testMode);
        $gateway->setReferrerId($this->referrerId);

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName()
    {
        return '\\'.Gateway::class;
    }
}
