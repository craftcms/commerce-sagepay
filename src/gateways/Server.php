<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\commerce\sagepay\gateways;

use Craft;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use Omnipay\Common\AbstractGateway;
use Omnipay\Omnipay;
use Omnipay\SagePay\Message\AbstractRequest;
use Omnipay\SagePay\Message\ServerNotifyRequest;
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
        $parent = $transaction->getParent();

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

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'sagepay');
            $response->data = 'ok';

            return $response;
        }

        // Get the mutex ready to release because this process can just exit the application
        $mutex = Craft::$app->getMutex();
        /** @var Gateway $gateway */
        $gateway = $this->gateway();

        /** @var ServerNotifyRequest $request */
        $request = $gateway->acceptNotification();
        $request->setTransactionReference($transaction->reference);

        if (!$request->isValid()) {
            $url = UrlHelper::siteUrl($transaction->getOrder()->cancelUrl);
            Craft::warning('Notification request is not valid: ' . json_encode($request->getData(), JSON_PRETTY_PRINT), 'sagepay');
            $request->invalid($url, 'Invalid signature');

            $mutex->release('commerceTransaction:' . $transactionHash);
            exit();
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        /** @var TransactionRecord|null $successChildTransaction */
        $successChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => [TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_AUTHORIZE],
        ])->one();

        if ($successChildTransaction) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');
            // At this point we could call `$transaction->getOrder()->updateOrderPaidInformation()` but SagePay is expecting
            // a URL and we know complete payment will update the information.
            $url = UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $successChildTransaction->id, 'commerceTransactionHash' => $successChildTransaction->hash]);
            $request->confirm($url);

            $mutex->release('commerceTransaction:' . $transactionHash);
            exit();
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

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

        $childTransaction->response = $request->getData();
        $childTransaction->code = $request->getCode();
        $childTransaction->reference = $request->getTransactionReference();
        $childTransaction->message = $request->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $url = UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $childTransaction->id, 'commerceTransactionHash' => $childTransaction->hash]);

        $request->confirm($url);

        // As of `omnipay-sagepay` version 3.2.2, the `confirm` call above starts output, so prevent Yii from erroring out by trying to send headers or anything, really.
        $mutex->release('commerceTransaction:' . $transactionHash);
        exit();
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

    /**
     * @param array $request
     * @param BasePaymentForm|null $paymentForm
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null)
    {
        parent::populateRequest($request, $paymentForm);
        $request['profile'] = $this->useLowProfile ? AbstractRequest::PROFILE_LOW : AbstractRequest::PROFILE_NORMAL;
    }

    /**
     * @inheritDoc
     */
    public function getTransactionHashFromWebhook()
    {
        return Craft::$app->getRequest()->getParam('commerceTransactionHash');
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
    protected function getGatewayClassName()
    {
        return '\\' . Gateway::class;
    }
}
