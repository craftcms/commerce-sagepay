<?php

namespace craft\commerce\sagepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\SagePay\Message\AbstractRequest;
use Omnipay\SagePay\Message\ServerNotifyRequest;
use Omnipay\SagePay\Message\ServerNotifyResponse;
use Omnipay\SagePay\ServerGateway as Gateway;
use yii\base\NotSupportedException;

/**
 * Server represents the Sage Pay Server gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class Server extends OffsiteGateway
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

    /**
     * @var bool Whether cart information should be sent to the payment gateway
     */
    public $sendCartInfo = false;

    /**
     * @var bool Whether legacy basket format should be used.
     * @see https://github.com/thephpleague/omnipay-sagepay#basket-format
     */
    public $useOldBasketFormat = false;

    /**
     * @var bool Whether low profile form should be used.
     */
    public $useLowProfile = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Sage Pay Server');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-sagepay/serverGatewaySettings', ['gateway' => $this]);
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
    public function processWebHook(): WebResponse
    {
        $response = Craft::$app->getResponse();

        $transactionHash = Craft::$app->getRequest()->getParam('commerceTransactionHash');
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        if (!$transaction) {
            Craft::warning('Transaction with the hash “'.$transactionHash.'” not found.', 'sagepay');
            $response->data = 'ok';

            return $response;
        }

        /** @var Gateway $gateway */
        $gateway = $this->gateway();

        /** @var ServerNotifyRequest $request */
        $request = $gateway->acceptNotification();
        $request->setTransactionReference($transaction->reference);

        /** @var ServerNotifyResponse $gatewayResponse */
        $gatewayResponse = $request->send();

        if (!$request->isValid()) {
            $url = UrlHelper::siteUrl($transaction->getOrder()->cancelUrl);
            Craft::warning('Notification request is not valid: '.json_encode($request->getData(), JSON_PRETTY_PRINT), 'sagepay');
            $gatewayResponse->invalid($url, 'Invalid signature');
            $response->data = 'ok';

            return $response;
        }

        $request->getData();

        $status = $request->getTransactionStatus();

        switch ($status) {
            case $request::STATUS_COMPLETED:
                $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
                break;
            case $request::STATUS_PENDING:
                $childTransaction->status = TransactionRecord::STATUS_PENDING;
                break;
            case $request::STATUS_FAILED:
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
                break;
        }

        $childTransaction->response = $gatewayResponse->getData();
        $childTransaction->code = $gatewayResponse->getCode();
        $childTransaction->reference = $gatewayResponse->getTransactionReference();
        $childTransaction->message = $gatewayResponse->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $url = UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $childTransaction->id, 'commerceTransactionHash' => $childTransaction->hash]);

        $gatewayResponse->confirm($url);
        $response->data = 'ok';

        return $response;
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
    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null)
    {
        parent::populateRequest($request, $paymentForm);
        $request['profile'] = $this->useLowProfile ? AbstractRequest::PROFILE_LOW : AbstractRequest::PROFILE_NORMAL;
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
        $gateway->setUseOldBasketFormat($this->useOldBasketFormat);

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
