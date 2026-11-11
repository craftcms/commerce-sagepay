<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay;

use CraftCms\Cms\Address\Elements\Address;
use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\BooleanMenu;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Commerce\Exceptions\NotImplementedException;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceSagePay\Data\Notification;
use CraftCms\CommerceSagePay\Events\BasketBuilding;
use CraftCms\CommerceSagePay\Events\PaymentRequestBuilding;
use CraftCms\CommerceSagePay\Responses\RegistrationResponse;
use CraftCms\CommerceSagePay\Responses\Response as SagePayResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Override;

use function CraftCms\Cms\t;

/**
 * Sage Pay Server gateway, using the Opayo Server and Shared protocol 4.00.
 *
 * Customers are redirected to Opayo’s payment page. Opayo then posts the result to the gateway’s webhook URL (the
 * Notification POST), and redirects the customer to the URL the webhook responds with.
 */
class Gateway extends BaseGateway
{
    /**
     * @var bool|string|null Whether to send the order’s line items and adjustments as the basket. Can be set to an environment variable.
     */
    public bool|string|null $sendCartInfo = false;

    /**
     * @var string|null The Opayo vendor name. Can be set to an environment variable.
     */
    public ?string $vendor = null;

    /**
     * @var bool|string|null Whether to use the Opayo sandbox. Can be set to an environment variable.
     */
    public bool|string|null $testMode = false;

    /**
     * @var string|null The partner referrer ID sent with registrations. Can be set to an environment variable.
     */
    public ?string $referrerId = null;

    /**
     * @var bool|string|null Whether to send the basket in the legacy `Basket` format rather than `BasketXML`. Can be set to an environment variable.
     */
    public bool|string|null $useOldBasketFormat = false;

    /**
     * @var bool|string|null Whether to use Opayo’s low profile payment page, which suits an iframe. Can be set to an environment variable.
     */
    public bool|string|null $useLowProfile = false;

    #[Override]
    public static function displayName(): string
    {
        return t('Sage Pay Server', category: 'commerce-sagepay');
    }

    public function getSendCartInfo(bool $parse = true): bool|string|null
    {
        return $parse ? (bool)Env::parseBoolean($this->sendCartInfo) : $this->sendCartInfo;
    }

    public function setSendCartInfo(bool|string|null $sendCartInfo): void
    {
        $this->sendCartInfo = $sendCartInfo;
    }

    public function getVendor(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->vendor) : $this->vendor;
    }

    public function setVendor(?string $vendor): void
    {
        $this->vendor = $vendor;
    }

    public function getTestMode(bool $parse = true): bool|string|null
    {
        return $parse ? (bool)Env::parseBoolean($this->testMode) : $this->testMode;
    }

    public function setTestMode(bool|string|null $testMode): void
    {
        $this->testMode = $testMode;
    }

    public function getReferrerId(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->referrerId) : $this->referrerId;
    }

    public function setReferrerId(?string $referrerId): void
    {
        $this->referrerId = $referrerId;
    }

    public function getUseOldBasketFormat(bool $parse = true): bool|string|null
    {
        return $parse ? (bool)Env::parseBoolean($this->useOldBasketFormat) : $this->useOldBasketFormat;
    }

    public function setUseOldBasketFormat(bool|string|null $useOldBasketFormat): void
    {
        $this->useOldBasketFormat = $useOldBasketFormat;
    }

    public function getUseLowProfile(bool $parse = true): bool|string|null
    {
        return $parse ? (bool)Env::parseBoolean($this->useLowProfile) : $this->useLowProfile;
    }

    public function setUseLowProfile(bool|string|null $useLowProfile): void
    {
        $this->useLowProfile = $useLowProfile;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'paymentType' => ['required', Rule::in(['authorize', 'purchase'])],
            'vendor' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        return Ui::make([
            Field::make(t('Send cart information to the gateway when making transactions?', category: 'commerce-sagepay'))
                ->control(BooleanMenu::make('sendCartInfo')
                    ->value(BooleanMenu::optionValue($this->sendCartInfo))
                    ->includeEnvVars()),
            Field::make(t('Vendor', category: 'commerce-sagepay'))
                ->required()
                ->control(Text::make('vendor')
                    ->value($this->vendor)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Test mode?', category: 'commerce-sagepay'))
                ->control(BooleanMenu::make('testMode')
                    ->value(BooleanMenu::optionValue($this->testMode))
                    ->includeEnvVars()),
            Field::make(t('Referrer ID', category: 'commerce-sagepay'))
                ->control(Text::make('referrerId')
                    ->value($this->referrerId)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Use legacy basket format?', category: 'commerce-sagepay'))
                ->instructions(t('Whether the basket should be sent in the legacy `Basket` format rather than `BasketXML`. The Sage 50 Accounts integration needs the legacy format.', category: 'commerce-sagepay'))
                ->control(BooleanMenu::make('useOldBasketFormat')
                    ->value(BooleanMenu::optionValue($this->useOldBasketFormat))
                    ->includeEnvVars()),
            Field::make(t('Use low profile payment form?', category: 'commerce-sagepay'))
                ->instructions(t('Whether the payment form should be optimised for inclusion as iframe.', category: 'commerce-sagepay'))
                ->control(BooleanMenu::make('useLowProfile')
                    ->value(BooleanMenu::optionValue($this->useLowProfile))
                    ->includeEnvVars()),
        ]);
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->register($transaction, $form, 'PAYMENT');
    }

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->register($transaction, $form, 'DEFERRED');
    }

    /**
     * Opayo reports the result of a payment to the webhook, which records it as a child transaction and sends the
     * customer to that transaction’s completion URL. So Commerce only calls this when the customer arrives some other
     * way, before the result is known.
     */
    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        return new SagePayResponse([
            'StatusDetail' => t('Sage Pay hasn’t reported the result of this payment.', category: 'commerce-sagepay'),
        ]);
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        return $this->completePurchase($transaction);
    }

    /**
     * Releases a `DEFERRED` transaction. `$reference` is the reference of the authorization being captured.
     */
    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $related = $this->relatedTransaction($reference);

        $data = array_merge($this->baseData('RELEASE'), [
            'ReleaseAmount' => $this->amount($transaction->paymentAmount, (string)$transaction->paymentCurrency),
            'VendorTxCode' => $related['VendorTxCode'],
            'VPSTxId' => $related['VPSTxId'],
            'SecurityKey' => $related['SecurityKey'],
            'TxAuthNo' => $related['TxAuthNo'],
        ]);

        return new SagePayResponse($this->client()->release($data));
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        // Captures don’t have a reference of their own, so refund the authorization they captured.
        $parent = $transaction->getParent();
        $reference = $parent?->type === TransactionModel::TYPE_CAPTURE ? $parent->getParent()?->reference : $transaction->reference;
        $related = $this->relatedTransaction($reference);

        $data = array_merge($this->baseData('REFUND'), [
            'Amount' => $this->amount($transaction->paymentAmount, (string)$transaction->paymentCurrency),
            'Currency' => $transaction->paymentCurrency,
            'Description' => $this->description($transaction),
            'RelatedVendorTxCode' => $related['VendorTxCode'],
            'RelatedVPSTxId' => $related['VPSTxId'],
            'RelatedSecurityKey' => $related['SecurityKey'],
            'RelatedTxAuthNo' => $related['TxAuthNo'],
            'VendorTxCode' => $transaction->hash,
        ]);

        return new SagePayResponse($this->client()->refund($data), $transaction->hash);
    }

    /**
     * Handles Opayo’s Notification POST, and responds with the URL Opayo should send the customer to.
     *
     * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/acknowledgement-of-notification-post
     */
    #[Override]
    public function processWebHook(): Response
    {
        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = $transactionHash ? app(Transactions::class)->getTransactionByHash($transactionHash) : null;

        if (!$transaction || (int)$transaction->gatewayId !== (int)$this->id || !$transaction->getOrder()) {
            Log::warning('Sage Pay notification: transaction with the hash “' . $transactionHash . '” not found.');

            return $this->notificationResponse('INVALID', Url::siteUrl(), 'Transaction not found.');
        }

        $notification = Notification::fromRequest(request());

        if (!$this->isValidNotification($notification, $transaction)) {
            Log::warning('Sage Pay notification for “' . $transactionHash . '” is not valid: ' . json_encode($notification->data, JSON_PRETTY_PRINT));

            return $this->notificationResponse('INVALID', Url::siteUrl((string)$transaction->getOrder()->cancelUrl), 'Invalid signature.');
        }

        // Opayo may send the notification again, so don’t record a second successful payment.
        $successfulChild = collect(app(Transactions::class)->getChildrenByTransactionId((int)$transaction->id))
            ->first(fn(Transaction $child) => $child->status === TransactionModel::STATUS_SUCCESS
                && in_array($child->type, [TransactionModel::TYPE_PURCHASE, TransactionModel::TYPE_AUTHORIZE], true));

        if ($successfulChild) {
            Log::warning('Sage Pay notification: a successful child transaction for “' . $transactionHash . '” already exists.');

            return $this->notificationResponse('OK', $this->completePaymentUrl($successfulChild));
        }

        $status = $notification->getStatus();
        $securityKey = SagePayResponse::decodeReference($transaction->reference)['SecurityKey'];

        $child = app(Transactions::class)->createTransaction(null, $transaction);
        $child->type = $transaction->type;
        $child->status = match ($status) {
            'OK', 'OK REPEATED', 'AUTHENTICATED', 'REGISTERED' => TransactionModel::STATUS_SUCCESS,
            'PENDING' => TransactionModel::STATUS_PENDING,
            default => TransactionModel::STATUS_FAILED,
        };
        $child->response = $notification->data;
        $child->code = $status;
        $child->reference = SagePayResponse::encodeReference(array_filter([
            'SecurityKey' => $securityKey,
            'TxAuthNo' => $notification->get('TxAuthNo'),
            'VPSTxId' => $notification->get('VPSTxId'),
            'VendorTxCode' => $notification->get('VendorTxCode'),
        ], fn($value) => $value !== null));
        $child->message = (string)$notification->get('StatusDetail');
        app(Transactions::class)->saveTransaction($child);

        return $this->notificationResponse('OK', $this->completePaymentUrl($child));
    }

    #[Override]
    public function getTransactionHashFromWebhook(): ?string
    {
        $hash = request()->query('commerceTransactionHash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Returns whether a notification is signed with the transaction’s `SecurityKey` and is about this transaction.
     */
    protected function isValidNotification(Notification $notification, Transaction $transaction): bool
    {
        $reference = SagePayResponse::decodeReference($transaction->reference);
        $txType = $transaction->type === TransactionModel::TYPE_AUTHORIZE ? 'DEFERRED' : 'PAYMENT';

        return $transaction->status === TransactionModel::STATUS_REDIRECT
            && isset($reference['SecurityKey'], $reference['VPSTxId'])
            && $notification->hasValidSignature((string)$this->getVendor(), $reference['SecurityKey'])
            && $notification->get('VPSTxId') === $reference['VPSTxId']
            && $notification->get('VendorTxCode') === $transaction->hash
            && $notification->get('TxType') === $txType;
    }

    /**
     * Registers a Server transaction, which returns the URL of Opayo’s payment page.
     */
    protected function register(Transaction $transaction, ?BasePaymentForm $form, string $txType): RequestResponseInterface
    {
        $event = new PaymentRequestBuilding($transaction, $form, $this->buildRegistrationRequest($transaction, $txType));
        event($event);

        return new RegistrationResponse($this->client()->register($event->request), $transaction->hash);
    }

    /**
     * Builds the body of the Transaction Registration request. It has the same fields the plugin has always sent.
     *
     * @return array<string, scalar|null>
     */
    protected function buildRegistrationRequest(Transaction $transaction, string $txType): array
    {
        $order = $transaction->getOrder() ?? throw new InvalidArgumentException('The transaction has no order.');
        $params = ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash];

        $request = array_merge($this->baseData($txType), [
            'Description' => $this->description($transaction),
            'Amount' => $this->amount($transaction->paymentAmount, (string)$transaction->paymentCurrency),
            'Currency' => $transaction->paymentCurrency,
            'VendorData' => null,
            'VendorTxCode' => $transaction->hash,
            'ClientIPAddress' => $this->clientIp(),
            'BrowserJavascriptEnabled' => 0,
            'BrowserLanguage' => 'en-GB',
            'ThreeDSNotificationURL' => null,
            'BrowserAcceptHeader' => request()->header('Accept'),
            'BrowserUserAgent' => request()->userAgent(),
            'ChallengeWindowSize' => '05',
            'BrowserJavaEnabled' => null,
            'BrowserColorDepth' => null,
            'BrowserScreenHeight' => null,
            'BrowserScreenWidth' => null,
            'BrowserTZ' => null,
            'MITType' => null,
            'COFUsage' => null,
            'InitiatedType' => null,
            'SchemeTraceID' => null,
            'RecurringExpiry' => null,
            'RecurringFrequency' => null,
            'ACSTransID' => null,
            'DSTransID' => null,
            'ApplyAVSCV2' => 0,
            'Apply3DSecure' => 0,
        ]);

        if ($referrerId = $this->getReferrerId()) {
            $request['ReferrerID'] = $referrerId;
        }

        $request = array_merge(
            $request,
            $this->addressData('Billing', $order->getBillingAddress()),
            $this->addressData('Delivery', $order->getShippingAddress()),
        );

        if ($email = $order->getEmail()) {
            $request['CustomerEMail'] = $email;
        }

        if ($this->getSendCartInfo()) {
            $request = array_merge($request, $this->basketData($transaction, $order));
        }

        $request['AllowGiftAid'] = 0;
        $request['NotificationURL'] = $this->getWebhookUrl($params);
        $request['Profile'] = $this->getUseLowProfile() ? 'LOW' : 'NORMAL';

        return $request;
    }

    /**
     * Returns the `Basket` or `BasketXML` field for the order, or nothing if the basket is empty.
     *
     * @return array<string, string>
     */
    protected function basketData(Transaction $transaction, Order $order): array
    {
        // The basket is in the order’s currency, and Opayo checks it against the payment amount.
        if ($transaction->currency !== $transaction->paymentCurrency) {
            Log::info('Sage Pay: not sending the basket, because the payment currency differs from the order currency.');

            return [];
        }

        $event = new BasketBuilding($transaction, $order, Basket::forOrder($order)->items);
        event($event);

        $basket = new Basket(array_values($event->items), (string)$transaction->currency);

        if ($this->getUseOldBasketFormat()) {
            return empty($basket->items) ? [] : ['Basket' => $basket->toLegacyString()];
        }

        $xml = $basket->toXml();

        return $xml === '' ? [] : ['BasketXML' => $xml];
    }

    /**
     * @return array<string, string|null>
     */
    protected function addressData(string $prefix, ?Address $address): array
    {
        $country = $address?->getCountryCode() ?: null;

        return [
            $prefix . 'Firstnames' => $address?->getGivenName(),
            $prefix . 'Surname' => $address?->getFamilyName(),
            $prefix . 'Address1' => $address?->getAddressLine1(),
            $prefix . 'Address2' => $address?->getAddressLine2(),
            $prefix . 'City' => $address?->getLocality(),
            $prefix . 'PostCode' => $address?->getPostalCode(),
            // Opayo only accepts a state for US addresses.
            $prefix . 'State' => $country === 'US' ? $address->getAdministrativeArea() : '',
            $prefix . 'Country' => $country,
            $prefix . 'Phone' => null,
        ];
    }

    /**
     * Returns the fields every request starts with.
     *
     * @return array<string, string|null>
     */
    protected function baseData(string $txType): array
    {
        return [
            'VPSProtocol' => Client::PROTOCOL_VERSION,
            'TxType' => $txType,
            'Vendor' => $this->getVendor(),
            'AccountType' => 'E',
        ];
    }

    /**
     * Decodes the reference of the transaction being captured or refunded.
     *
     * @return array{VendorTxCode: string, VPSTxId: string, SecurityKey: string, TxAuthNo: string}
     */
    protected function relatedTransaction(?string $reference): array
    {
        $related = SagePayResponse::decodeReference($reference);

        foreach (['VendorTxCode', 'VPSTxId', 'SecurityKey', 'TxAuthNo'] as $key) {
            if (($related[$key] ?? '') === '') {
                throw new InvalidArgumentException(sprintf('The transaction reference is missing the %s.', $key));
            }
        }

        /** @var array{VendorTxCode: string, VPSTxId: string, SecurityKey: string, TxAuthNo: string} $related */
        return $related;
    }

    protected function description(Transaction $transaction): string
    {
        return t('Order', category: 'commerce') . ' #' . $transaction->orderId;
    }

    /**
     * Formats an amount with the currency’s decimal places, such as `12.30`, or `1235` for JPY.
     */
    protected function amount(float $amount, string $currency): string
    {
        return app(Currencies::class)->getTeller($currency)->convertToString($amount);
    }

    /**
     * Returns the customer’s IP address. Opayo only accepts IPv4 addresses.
     */
    protected function clientIp(): ?string
    {
        $ip = request()->ip();

        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        return $ip !== null && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip) ? $ip : null;
    }

    protected function completePaymentUrl(Transaction $transaction): string
    {
        return Url::actionUrl('commerce/payments/complete-payment', [
            'commerceTransactionId' => $transaction->id,
            'commerceTransactionHash' => $transaction->hash,
        ]);
    }

    /**
     * Returns the plain text response Opayo expects, which must start with `Status=`.
     */
    protected function notificationResponse(string $status, string $redirectUrl, ?string $detail = null): Response
    {
        $lines = ['Status=' . $status, 'RedirectURL=' . $redirectUrl];

        if ($detail !== null) {
            $lines[] = 'StatusDetail=' . $detail;
        }

        return response(implode("\r\n", $lines), 200, ['Content-Type' => 'text/plain']);
    }

    protected function client(): Client
    {
        return new Client((bool)$this->getTestMode());
    }

    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(t('Payment sources are not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function supportsAuthorize(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return true;
    }
}
