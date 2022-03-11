<?php

namespace craft\commerce\sagepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\omnipay\base\CreditCardGateway;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\SagePay\DirectGateway as Gateway;
use yii\base\NotSupportedException;

/**
 * Direct represents Sage Pay Direct gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property-read null|string $settingsHtml
 */
class Direct extends CreditCardGateway
{
    /**
     * @var string|null
     */
    public ?string $vendor = null;

    /**
     * @var bool
     */
    public bool $testMode = false;

    /**
     * @var string|null
     */
    public ?string $referrerId = null;

    /**
     * @var bool Whether cart information should be sent to the payment gateway
     */
    public bool $sendCartInfo = false;


    /**
     * @var bool Whether legacy basket format should be used.
     * @see https://github.com/thephpleague/omnipay-sagepay#basket-format
     */
    public bool $useOldBasketFormat = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Sage Pay Direct');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-sagepay/directGatewaySettings', ['gateway' => $this]);
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
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null)
    {
        parent::populateRequest($request, $paymentForm);
        if (isset($request['returnUrl'])) {
            $request['ThreeDSNotificationURL'] = $request['returnUrl'];
        }
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var Gateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setVendor(Craft::parseEnv($this->vendor));
        $gateway->setReferrerId(Craft::parseEnv($this->referrerId));
        $gateway->setTestMode($this->testMode);
        $gateway->setUseOldBasketFormat($this->useOldBasketFormat);

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\'.Gateway::class;
    }
}
