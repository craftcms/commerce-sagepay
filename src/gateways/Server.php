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
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use Omnipay\Common\AbstractGateway;
use Omnipay\SagePay\Message\AbstractRequest;
use Omnipay\SagePay\Message\ServerNotifyRequest;
use Omnipay\SagePay\ServerGateway as Gateway;
use yii\base\NotSupportedException;

/**
 * Server represents the Sage Pay Server gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property bool $sendCartInfo
 * @property bool $testMode
 * @property bool $useLowProfile
 * @property bool $useOldBasketFormat
 * @property string $referrerId
 * @property string $vendor
 * @property-read null|string $settingsHtml
 */
class Server extends OffsiteGateway
{
    /**
     * @var string|null
     */
    private ?string $_vendor = null;

    /**
     * @var bool|string
     */
    private bool|string $_testMode = false;

    /**
     * @var string|null
     */
    private ?string $_referrerId = null;

    /**
     * @var bool|string Whether legacy basket format should be used.
     * @see https://github.com/thephpleague/omnipay-sagepay#basket-format
     */
    private bool|string $_useOldBasketFormat = false;

    /**
     * @var bool|string Whether low profile form should be used.
     */
    private bool|string $_useLowProfile = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Sage Pay Server');
    }

    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['vendor'] = $this->getVendor(false);
        $settings['testMode'] = $this->getTestMode(false);
        $settings['referrerId'] = $this->getReferrerId(false);
        $settings['useOldBasketFormat'] = $this->getUseOldBasketFormat(false);
        $settings['useLowProfile'] = $this->getUseLowProfile(false);

        return $settings;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-sagepay/serverGatewaySettings', ['gateway' => $this]);
    }

    /**
     * Returns whether Test Mode should be used.
     *
     * @param bool $parse Whether to parse the value as an environment variable
     * @return bool|string
     * @since 5.0.0
     */
    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_testMode) : $this->_testMode;
    }

    /**
     * Sets whether Test Mode should be used.
     *
     * @param string|bool $testMode
     * @since 5.0.0
     */
    public function setTestMode(bool|string $testMode): void
    {
        $this->_testMode = $testMode;
    }

    /**
     * @param bool $parse Whether to parse the value as an environment variable
     * @return bool|string
     * @since 5.0.0
     */
    public function getUseLowProfile(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_useLowProfile) : $this->_useLowProfile;
    }

    /**
     * @param string|bool $useLowProfile
     * @since 5.0.0
     */
    public function setUseLowProfile(bool|string $useLowProfile): void
    {
        $this->_useLowProfile = $useLowProfile;
    }

    /**
     * @param bool $parse Whether to parse the value as an environment variable
     * @return bool|string
     * @since 5.0.0
     */
    public function getUseOldBasketFormat(bool $parse = true): bool|string
    {
        return $parse ? App::parseBooleanEnv($this->_useOldBasketFormat) : $this->_useOldBasketFormat;
    }

    /**
     * @param string|bool $useOldBasketFormat
     * @since 5.0.0
     */
    public function setUseOldBasketFormat(bool|string $useOldBasketFormat): void
    {
        $this->_useOldBasketFormat = $useOldBasketFormat;
    }

    /**
     * @param bool $parse Whether to parse the value as an environment variable
     * @return ?string
     * @since 5.0.0
     */
    public function getVendor(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_vendor) : $this->_vendor;
    }

    /**
     * @param ?string $vendor
     * @since 5.0.0
     */
    public function setVendor(?string $vendor): void
    {
        $this->_vendor = $vendor;
    }

    /**
     * @param bool $parse Whether to parse the value as an environment variable
     * @return ?string
     * @since 5.0.0
     */
    public function getReferrerId(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_referrerId) : $this->_referrerId;
    }

    /**
     * @param ?string $referrerId
     * @since 5.0.0
     */
    public function setReferrerId(?string $referrerId): void
    {
        $this->_referrerId = $referrerId;
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
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null): void
    {
        parent::populateRequest($request, $paymentForm);
        $request['profile'] = $this->getUseLowProfile() ? AbstractRequest::PROFILE_LOW : AbstractRequest::PROFILE_NORMAL;
    }

    /**
     * @inheritDoc
     */
    public function getTransactionHashFromWebhook(): ?string
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

        $gateway->setVendor($this->getVendor());
        $gateway->setReferrerId($this->getReferrerId());
        $gateway->setTestMode($this->getTestMode());
        $gateway->setUseOldBasketFormat($this->getUseOldBasketFormat());

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\' . Gateway::class;
    }
}
