<?php

namespace craft\commerce\sagepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\omnipay\base\CreditCardGateway;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\SagePay\DirectGateway as Gateway;
use yii\base\NotSupportedException;

/**
 * Direct represents SagePay Direct gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Direct extends CreditCardGateway
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

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        if (!$this->supportsRefund()) {
            throw new NotSupportedException(Craft::t('commerce', 'Refunding is not supported by this gateway'));
        }

        $request = $this->createRequest($transaction);
        $parent= $transaction->getParent();

        if ($parent->type == TransactionRecord::TYPE_CAPTURE) {
            $reference = $parent->getParent()->reference;
        } else {
            $reference = $transaction->reference;
        }

        $refundRequest = $this->prepareRefundRequest($request, $reference);

        return $this->performRequest($refundRequest, $transaction);
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
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
