<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\OffsitePaymentForm;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\CommerceSagePay\Data\BasketItem;
use CraftCms\CommerceSagePay\Events\BasketBuilding;
use CraftCms\CommerceSagePay\Events\PaymentRequestBuilding;
use CraftCms\CommerceSagePay\Gateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const SAGEPAY_REGISTER_URL = 'https://live.opayo.eu.elavon.com/gateway/service/vspserver-register.vsp';
const SAGEPAY_VPS_TX_ID = '{C0F3A1B2-3D4E-5F60-7182-93A4B5C6D7E8}';
const SAGEPAY_SECURITY_KEY = 'XXXXXXXXXX';

function sagePayGateway(array $settings = []): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'Sage Pay',
        'handle' => 'sagePay',
        'paymentType' => 'purchase',
        'settings' => array_merge(['vendor' => 'CraftShop'], $settings),
    ]);

    return $gateway;
}

/**
 * Saves a gateway and an order, and returns an unsaved transaction for it.
 */
function sagePayTransaction(float $amount = 12.3, string $type = TransactionModel::TYPE_PURCHASE, array $settings = []): Transaction
{
    $gateway = sagePayGateway($settings);
    app(Gateways::class)->saveGateway($gateway);

    $order = new Order();
    $order->email = 'customer@example.com';
    $order->gatewayId = $gateway->id;
    $order->cancelUrl = 'shop/checkout';
    Elements::saveElement($order);

    $transaction = app(Transactions::class)->createTransaction($order, null, $type);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

/**
 * Saves a transaction as Commerce does after a successful registration: redirected, with the registration reference.
 */
function sagePayRegisteredTransaction(string $type = TransactionModel::TYPE_PURCHASE): Transaction
{
    $transaction = sagePayTransaction(type: $type);
    $transaction->hash = '0123456789abcdef0123456789abcdef';
    $transaction->status = TransactionModel::STATUS_REDIRECT;
    $transaction->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => $transaction->hash]);
    app(Transactions::class)->saveTransaction($transaction);

    return $transaction;
}

/**
 * Returns the fields of an Opayo notification POST for a successful payment.
 */
function sagePayNotification(array $fields = []): array
{
    return array_merge([
        'VPSProtocol' => '4.00',
        'TxType' => 'PAYMENT',
        'VendorTxCode' => '0123456789abcdef0123456789abcdef',
        'VPSTxId' => SAGEPAY_VPS_TX_ID,
        'Status' => 'OK',
        'StatusDetail' => '0000 : The Authorisation was Successful.',
        'TxAuthNo' => '8661237',
        'AVSCV2' => 'SECURITY CODE MATCH ONLY',
        'AddressResult' => 'NOTMATCHED',
        'PostCodeResult' => 'MATCHED',
        'CV2Result' => 'MATCHED',
        'GiftAid' => '0',
        '3DSecureStatus' => 'OK',
        'CAVV' => 'AAABARR5kwAAAAAAAAAAAAAAAAA',
        'CardType' => 'VISA',
        'Last4Digits' => '0006',
        'DeclineCode' => '00',
        'ExpiryDate' => '0129',
        'BankAuthCode' => '999777',
        'ACSTransID' => '6a8c1f0e-1d6b-4c5b-9a0e-3a4f5e6d7c8b',
        'DSTransID' => 'f25084f0-5b16-4c0a-ae5d-b24808a95e4b',
        'SchemeTraceID' => 'SP9876543210',
    ], $fields);
}

/**
 * Signs notification fields as Opayo documents it: the uppercase MD5 of the values of VPSTxId, VendorTxCode, Status,
 * TxAuthNo, the lowercase vendor name, AVSCV2, the SecurityKey, and the remaining result fields, in that order.
 */
function sagePaySign(array $fields, string $vendor = 'CraftShop', string $securityKey = SAGEPAY_SECURITY_KEY): array
{
    $value = fn(string $name) => $fields[$name] ?? '';
    $fields['VPSSignature'] = strtoupper(md5(
        $value('VPSTxId') . $value('VendorTxCode') . $value('Status') . $value('TxAuthNo') . strtolower($vendor)
        . $value('AVSCV2') . $securityKey . $value('AddressResult') . $value('PostCodeResult') . $value('CV2Result')
        . $value('GiftAid') . $value('3DSecureStatus') . $value('CAVV') . $value('AddressStatus') . $value('PayerStatus')
        . $value('CardType') . $value('Last4Digits') . $value('DeclineCode') . $value('ExpiryDate') . $value('FraudResponse')
        . $value('BankAuthCode') . $value('ACSTransID') . $value('DSTransID') . $value('SchemeTraceID')
    ));

    return $fields;
}

/**
 * Posts a form-encoded notification to the gateway’s webhook URL, as Opayo does.
 */
function postSagePayNotification(Transaction $transaction, array $fields, ?string $hash = null): Illuminate\Testing\TestResponse
{
    $url = $transaction->getGateway()->getWebhookUrl([
        'commerceTransactionId' => $transaction->id,
        'commerceTransactionHash' => $hash ?? $transaction->hash,
    ]);

    return test()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
}

function sagePayBody(string $body): array
{
    return CraftCms\CommerceSagePay\Client::parse($body);
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the Craft Commerce 5 class name and settings', function() {
    $settings = [
        'sendCartInfo' => '$SAGEPAY_SEND_CART',
        'vendor' => '$SAGEPAY_VENDOR',
        'testMode' => true,
        'referrerId' => 'ref123',
        'useOldBasketFormat' => '1',
        'useLowProfile' => false,
    ];

    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\sagepay\\gateways\\Server',
        'settings' => $settings,
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toEqual($settings)
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class)
        ->and($gateway->getTestMode())->toBeTrue()
        ->and($gateway->getUseOldBasketFormat())->toBeTrue()
        ->and($gateway->getUseLowProfile())->toBeFalse()
        ->and($gateway->getVendor(false))->toBe('$SAGEPAY_VENDOR');
});

it('parses environment variables in the settings', function() {
    $_ENV['SAGEPAY_TEST_VENDOR'] = 'EnvShop';
    $_ENV['SAGEPAY_TEST_MODE'] = 'true';
    putenv('SAGEPAY_TEST_VENDOR=EnvShop');
    putenv('SAGEPAY_TEST_MODE=true');

    $gateway = sagePayGateway(['vendor' => '$SAGEPAY_TEST_VENDOR', 'testMode' => '$SAGEPAY_TEST_MODE']);

    expect($gateway->getVendor())->toBe('EnvShop')
        ->and($gateway->getTestMode())->toBeTrue()
        ->and($gateway->getTestMode(false))->toBe('$SAGEPAY_TEST_MODE');
});

it('requires a vendor and a known payment type', function() {
    $gateway = sagePayGateway(['vendor' => '']);
    $gateway->paymentType = 'subscription';

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('vendor', 'paymentType');

    $gateway = sagePayGateway();
    $gateway->paymentType = 'authorize';

    expect($gateway->validate())->toBeTrue();
});

it('renders the settings', function() {
    $html = sagePayGateway(['vendor' => '$SAGEPAY_VENDOR', 'referrerId' => 'ref123'])->getSettingsHtml();

    expect($html)
        ->toContain('$SAGEPAY_VENDOR')
        ->toContain('ref123')
        ->toContain('sendCartInfo')
        ->toContain('testMode')
        ->toContain('useOldBasketFormat')
        ->toContain('useLowProfile');
});

it('has no payment form, because customers enter their card details on Opayo’s payment page', function() {
    expect(sagePayGateway()->paymentFormUi(new OffsitePaymentForm()))->toBeNull()
        ->and(sagePayGateway()->getPaymentFormHtml([]))->toBeEmpty()
        ->and(sagePayGateway()->getPaymentFormModel())->toBeInstanceOf(OffsitePaymentForm::class);
});

it('registers a payment and redirects to the Opayo payment page', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response(implode("\r\n", [
        'VPSProtocol=4.00',
        'Status=OK',
        'StatusDetail=2014 : The Transaction was Registered Successfully.',
        'VPSTxId=' . SAGEPAY_VPS_TX_ID,
        'SecurityKey=' . SAGEPAY_SECURITY_KEY,
        'NextURL=https://live.opayo.eu.elavon.com/gateway/service/cardselection?vpstxid=' . SAGEPAY_VPS_TX_ID,
    ]))]);
    $transaction = sagePayTransaction(12.3);
    $transaction->getOrder()->setBillingAddress([
        'firstName' => 'Jenny',
        'lastName' => 'Andrews',
        'addressLine1' => '88 Test Street',
        'addressLine2' => 'Flat 2',
        'locality' => 'London',
        'postalCode' => 'EC1A 1AA',
        'countryCode' => 'GB',
        'administrativeArea' => 'Greater London',
    ]);

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeTrue()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->getRedirectMethod())->toBe('GET')
        ->and($response->getRedirectUrl())->toBe('https://live.opayo.eu.elavon.com/gateway/service/cardselection?vpstxid=' . SAGEPAY_VPS_TX_ID)
        ->and(json_decode($response->getTransactionReference(), true))->toBe([
            'SecurityKey' => SAGEPAY_SECURITY_KEY,
            'VPSTxId' => SAGEPAY_VPS_TX_ID,
            'VendorTxCode' => $transaction->hash,
        ]);

    Http::assertSent(function(Request $request) use ($transaction) {
        parse_str($request->body(), $body);

        return $request->url() === SAGEPAY_REGISTER_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $body['VPSProtocol'] === '4.00'
            && $body['TxType'] === 'PAYMENT'
            && $body['Vendor'] === 'CraftShop'
            && $body['AccountType'] === 'E'
            && $body['VendorTxCode'] === $transaction->hash
            && $body['Amount'] === '12.30'
            && $body['Currency'] === 'USD'
            && $body['Description'] === 'Order #' . $transaction->orderId
            && $body['BillingFirstnames'] === 'Jenny'
            && $body['BillingSurname'] === 'Andrews'
            && $body['BillingAddress1'] === '88 Test Street'
            && $body['BillingAddress2'] === 'Flat 2'
            && $body['BillingCity'] === 'London'
            && $body['BillingPostCode'] === 'EC1A 1AA'
            && $body['BillingCountry'] === 'GB'
            // Opayo only accepts a state for US addresses.
            && $body['BillingState'] === ''
            && $body['DeliverySurname'] === ''
            && $body['DeliveryCountry'] === ''
            && $body['CustomerEMail'] === 'customer@example.com'
            && $body['ApplyAVSCV2'] === '0'
            && $body['Apply3DSecure'] === '0'
            && $body['AllowGiftAid'] === '0'
            && $body['Profile'] === 'NORMAL'
            // Empty fields are still posted, as the Omnipay driver did.
            && $body['VendorData'] === ''
            && !isset($body['ReferrerID'], $body['BasketXML'], $body['Basket'])
            && str_contains($body['NotificationURL'], 'commerceTransactionHash=' . $transaction->hash)
            && str_contains($body['NotificationURL'], 'gateway=' . $transaction->gatewayId);
    });
});

it('registers a deferred payment for authorizations, in test mode', function() {
    Http::fake(['https://sandbox.opayo.eu.elavon.com/*' => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://sandbox.opayo.eu.elavon.com/next")]);
    $transaction = sagePayTransaction(type: TransactionModel::TYPE_AUTHORIZE, settings: [
        'testMode' => '1',
        'useLowProfile' => true,
        'referrerId' => 'ref123',
    ]);
    $transaction->getOrder()->setShippingAddress([
        'firstName' => 'Sam',
        'lastName' => 'Smith',
        'addressLine1' => '1 Main St',
        'locality' => 'Springfield',
        'postalCode' => '62701',
        'countryCode' => 'US',
        'administrativeArea' => 'IL',
    ]);

    $response = $transaction->getGateway()->authorize($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeTrue();

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return $request->url() === 'https://sandbox.opayo.eu.elavon.com/gateway/service/vspserver-register.vsp'
            && $body['TxType'] === 'DEFERRED'
            && $body['Profile'] === 'LOW'
            && $body['ReferrerID'] === 'ref123'
            && $body['DeliveryFirstnames'] === 'Sam'
            && $body['DeliveryCountry'] === 'US'
            && $body['DeliveryState'] === 'IL';
    });
});

it('formats amounts with the payment currency’s decimal places', function(float $amount, string $currency, string $expected) {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://example.com")]);
    $transaction = sagePayTransaction($amount);
    $transaction->paymentCurrency = $currency;
    $transaction->paymentAmount = $amount;

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(function(Request $request) use ($currency, $expected) {
        parse_str($request->body(), $body);

        return $body['Amount'] === $expected && $body['Currency'] === $currency;
    });
})->with([
    'cents' => [1234.5, 'GBP', '1234.50'],
    'zero-decimal currency' => [1235.0, 'JPY', '1235'],
]);

it('reports a rejected registration as a failed payment', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("VPSProtocol=4.00\r\nStatus=INVALID\r\nStatusDetail=3048 : The CardNumber length is invalid.")]);
    $transaction = sagePayTransaction();

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeFalse()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getCode())->toBe('INVALID')
        ->and($response->getMessage())->toBe('3048 : The CardNumber length is invalid.')
        ->and($response->getTransactionReference())->toBe('');
});

it('reports an empty response as a failed payment', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response('', 500)]);
    $transaction = sagePayTransaction();

    $response = $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    expect($response->isRedirect())->toBeFalse()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Unable to reach Sage Pay.');
});

it('lets listeners modify the registration request', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://example.com")]);
    Event::listen(function(PaymentRequestBuilding $event) {
        $event->request['Language'] = 'DE';
        $event->request['VendorData'] = 'Craft order';
    });
    $transaction = sagePayTransaction();

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return $body['Language'] === 'DE' && $body['VendorData'] === 'Craft order';
    });
});

it('sends the basket as BasketXML, with discounts', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://example.com")]);
    Event::listen(function(BasketBuilding $event) {
        $event->items[] = new BasketItem('Widget & Co™', 2, 10.5);
        $event->items[] = new BasketItem('Summer sale', 1, -5);
    });
    $transaction = sagePayTransaction(16, settings: ['sendCartInfo' => true]);

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);
        $xml = simplexml_load_string($body['BasketXML']);

        return !isset($body['Basket'])
            && (string)$xml->item->description === 'Widget & Co'
            && (string)$xml->item->quantity === '2'
            && (string)$xml->item->unitNetAmount === '10.50'
            && (string)$xml->item->unitTaxAmount === '0.00'
            && (string)$xml->item->unitGrossAmount === '10.50'
            && (string)$xml->item->totalGrossAmount === '21.00'
            && count($xml->item) === 1
            && (string)$xml->discounts->discount->fixed === '5.00'
            && (string)$xml->discounts->discount->description === 'Summer sale';
    });
});

it('sends the basket in the legacy format, with product codes', function() {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://example.com")]);
    Event::listen(function(BasketBuilding $event) {
        $event->items[] = new BasketItem('Widget & Co', 2, 10.5, productCode: 'SKU-1');
        $event->items[] = new BasketItem('Summer sale', 1, -5);
    });
    $transaction = sagePayTransaction(16, settings: ['sendCartInfo' => '1', 'useOldBasketFormat' => true]);

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return !isset($body['BasketXML'])
            && $body['Basket'] === '2:[SKU-1]Widget  Co:2:10.50:0.00:10.50:21.00:Summer sale:1:-5.00:0.00:-5.00:-5.00';
    });
});

it('doesn’t send an empty basket, or a basket in a different currency', function(string $paymentCurrency, bool $addItem) {
    Http::fake([SAGEPAY_REGISTER_URL => Http::response("Status=OK\r\nVPSTxId={X}\r\nSecurityKey=KEY\r\nNextURL=https://example.com")]);
    Event::listen(function(BasketBuilding $event) use ($addItem) {
        if ($addItem) {
            $event->items[] = new BasketItem('Widget', 1, 10);
        }
    });
    $transaction = sagePayTransaction(10, settings: ['sendCartInfo' => true]);
    $transaction->paymentCurrency = $paymentCurrency;

    $transaction->getGateway()->purchase($transaction, new OffsitePaymentForm());

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return !isset($body['BasketXML']) && !isset($body['Basket']);
    });
})->with([
    'empty basket' => ['USD', false],
    'different currency' => ['EUR', true],
]);

it('builds the basket from the order’s line items and adjustments', function() {
    $order = new Order();
    $order->currency = 'USD';
    $order->setAdjustments([
        new CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'Shipping', 'type' => 'shipping', 'amount' => 4.5]),
        new CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'VAT', 'type' => 'tax', 'amount' => 2, 'included' => true]),
        new CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => '', 'type' => 'discount', 'amount' => -1.25]),
        new CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'Free', 'type' => 'discount', 'amount' => 0]),
    ]);

    $items = CraftCms\CommerceSagePay\Basket::forOrder($order)->items;

    expect(array_map(fn(BasketItem $item) => [$item->description, $item->quantity, $item->price], $items))->toBe([
        ['Shipping', 1, 4.5],
        ['discount 1', 1, -1.25],
    ]);
});

it('captures an authorization with a RELEASE', function() {
    Http::fake(['https://live.opayo.eu.elavon.com/gateway/service/release.vsp' => Http::response("VPSProtocol=4.00\r\nStatus=OK\r\nStatusDetail=2004 : The Release was Successful.")]);
    $authorization = sagePayTransaction(20, TransactionModel::TYPE_AUTHORIZE);
    $authorization->status = TransactionModel::STATUS_SUCCESS;
    $authorization->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'TxAuthNo' => '8661237', 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => 'abc']);
    app(Transactions::class)->saveTransaction($authorization);

    $capture = app(Transactions::class)->createTransaction(null, $authorization, TransactionModel::TYPE_CAPTURE);
    $response = $authorization->getGateway()->capture($capture, $authorization->reference);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getMessage())->toBe('2004 : The Release was Successful.');

    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return $body === [
            'VPSProtocol' => '4.00',
            'TxType' => 'RELEASE',
            'Vendor' => 'CraftShop',
            'AccountType' => 'E',
            'ReleaseAmount' => '20.00',
            'VendorTxCode' => 'abc',
            'VPSTxId' => SAGEPAY_VPS_TX_ID,
            'SecurityKey' => SAGEPAY_SECURITY_KEY,
            'TxAuthNo' => '8661237',
        ];
    });
});

it('refunds a payment', function() {
    Http::fake(['https://live.opayo.eu.elavon.com/gateway/service/refund.vsp' => Http::response("VPSProtocol=4.00\r\nStatus=OK\r\nStatusDetail=0000 : The Authorisation was Successful.\r\nVPSTxId={REFUND-ID}\r\nTxAuthNo=8661999")]);
    $purchase = sagePayTransaction(20);
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    $purchase->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'TxAuthNo' => '8661237', 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => 'abc']);
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);
    $refund->paymentAmount = 5;
    $response = $purchase->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeTrue()
        ->and(json_decode($response->getTransactionReference(), true))->toBe([
            'TxAuthNo' => '8661999',
            'VPSTxId' => '{REFUND-ID}',
            'VendorTxCode' => $refund->hash,
        ]);

    Http::assertSent(function(Request $request) use ($refund) {
        parse_str($request->body(), $body);

        return $body === [
            'VPSProtocol' => '4.00',
            'TxType' => 'REFUND',
            'Vendor' => 'CraftShop',
            'AccountType' => 'E',
            'Amount' => '5.00',
            'Currency' => 'USD',
            'Description' => 'Order #' . $refund->orderId,
            'RelatedVendorTxCode' => 'abc',
            'RelatedVPSTxId' => SAGEPAY_VPS_TX_ID,
            'RelatedSecurityKey' => SAGEPAY_SECURITY_KEY,
            'RelatedTxAuthNo' => '8661237',
            'VendorTxCode' => $refund->hash,
        ];
    });
});

it('refunds a captured authorization using the authorization’s reference', function() {
    Http::fake(['*/refund.vsp' => Http::response("Status=OK\r\nVPSTxId={REFUND-ID}\r\nTxAuthNo=8661999")]);
    $authorization = sagePayTransaction(20, TransactionModel::TYPE_AUTHORIZE);
    $authorization->status = TransactionModel::STATUS_SUCCESS;
    $authorization->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'TxAuthNo' => '8661237', 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => 'abc']);
    app(Transactions::class)->saveTransaction($authorization);

    // Releases don’t return a reference.
    $capture = app(Transactions::class)->createTransaction(null, $authorization, TransactionModel::TYPE_CAPTURE);
    $capture->status = TransactionModel::STATUS_SUCCESS;
    $capture->reference = '';
    app(Transactions::class)->saveTransaction($capture);

    $refund = app(Transactions::class)->createTransaction(null, $capture, TransactionModel::TYPE_REFUND);
    $response = $capture->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeTrue();
    Http::assertSent(function(Request $request) {
        parse_str($request->body(), $body);

        return $body['RelatedTxAuthNo'] === '8661237' && $body['RelatedVendorTxCode'] === 'abc' && $body['Amount'] === '20.00';
    });
});

it('reports refunds Opayo rejects as failed', function() {
    Http::fake(['*/refund.vsp' => Http::response("VPSProtocol=4.00\r\nStatus=INVALID\r\nStatusDetail=4013 : The Amount to refund exceeds the original transaction.")]);
    $purchase = sagePayTransaction(20);
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    $purchase->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'TxAuthNo' => '8661237', 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => 'abc']);
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);
    $response = $purchase->getGateway()->refund($refund);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('4013 : The Amount to refund exceeds the original transaction.');
});

it('doesn’t refund a transaction without an authorization number', function() {
    Http::fake();
    $purchase = sagePayTransaction(20);
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    $purchase->reference = json_encode(['SecurityKey' => SAGEPAY_SECURITY_KEY, 'VPSTxId' => SAGEPAY_VPS_TX_ID, 'VendorTxCode' => 'abc']);
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Transactions::class)->createTransaction(null, $purchase, TransactionModel::TYPE_REFUND);

    expect(fn() => $purchase->getGateway()->refund($refund))->toThrow(InvalidArgumentException::class, 'TxAuthNo');
    Http::assertNothingSent();
});

it('matches the notification signature computed by the Omnipay driver', function() {
    $notification = new CraftCms\CommerceSagePay\Data\Notification(sagePayNotification(['VPSSignature' => '2C0B52EB265A9A536BA13997C8AF0894']));

    expect($notification->hasValidSignature('CraftShop', SAGEPAY_SECURITY_KEY))->toBeTrue()
        ->and($notification->hasValidSignature('CraftShop', 'OTHERKEY12'))->toBeFalse()
        ->and($notification->hasValidSignature('OtherShop', SAGEPAY_SECURITY_KEY))->toBeFalse();
});

it('records a successful payment from the notification, and sends the customer to complete it', function() {
    $transaction = sagePayRegisteredTransaction();

    $response = postSagePayNotification($transaction, sagePaySign(sagePayNotification()));

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($children)->toHaveCount(1);

    $child = $children[0];
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toStartWith("Status=OK\r\nRedirectURL=")
        ->and(sagePayBody($response->getContent()))->toBe([
            'Status' => 'OK',
            'RedirectURL' => CraftCms\Cms\Support\Url::actionUrl('commerce/payments/complete-payment', [
                'commerceTransactionId' => $child->id,
                'commerceTransactionHash' => $child->hash,
            ]),
        ])
        ->and($child->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($child->type)->toBe(TransactionModel::TYPE_PURCHASE)
        ->and($child->code)->toBe('OK')
        ->and($child->message)->toBe('0000 : The Authorisation was Successful.')
        ->and(json_decode($child->reference, true))->toBe([
            'SecurityKey' => SAGEPAY_SECURITY_KEY,
            'TxAuthNo' => '8661237',
            'VPSTxId' => SAGEPAY_VPS_TX_ID,
            'VendorTxCode' => $transaction->hash,
        ]);
});

it('records deferred payments as authorizations', function() {
    $transaction = sagePayRegisteredTransaction(TransactionModel::TYPE_AUTHORIZE);

    postSagePayNotification($transaction, sagePaySign(sagePayNotification(['TxType' => 'DEFERRED'])));

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($children)->toHaveCount(1)
        ->and($children[0]->type)->toBe(TransactionModel::TYPE_AUTHORIZE)
        ->and($children[0]->status)->toBe(TransactionModel::STATUS_SUCCESS);
});

it('records the status Opayo reports', function(string $status, string $expected) {
    $transaction = sagePayRegisteredTransaction();
    $fields = sagePayNotification(['Status' => $status, 'StatusDetail' => 'Detail for ' . $status]);
    unset($fields['TxAuthNo']);

    $response = postSagePayNotification($transaction, sagePaySign($fields));

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect(sagePayBody($response->getContent())['Status'])->toBe('OK')
        ->and($children)->toHaveCount(1)
        ->and($children[0]->status)->toBe($expected)
        ->and($children[0]->code)->toBe($status)
        ->and($children[0]->message)->toBe('Detail for ' . $status)
        ->and(json_decode($children[0]->reference, true))->not->toHaveKey('TxAuthNo');
})->with([
    'not authorised' => ['NOTAUTHED', TransactionModel::STATUS_FAILED],
    'aborted' => ['ABORT', TransactionModel::STATUS_FAILED],
    'rejected' => ['REJECTED', TransactionModel::STATUS_FAILED],
    'error' => ['ERROR', TransactionModel::STATUS_FAILED],
    'pending' => ['PENDING', TransactionModel::STATUS_PENDING],
]);

it('accepts lowercase signatures and values with surrounding spaces', function() {
    $transaction = sagePayRegisteredTransaction();
    // Laravel trims input, so a wrong implementation would sign "DATA NOT CHECKED" instead.
    $fields = sagePaySign(sagePayNotification(['AVSCV2' => 'DATA NOT CHECKED ']));
    $fields['VPSSignature'] = strtolower($fields['VPSSignature']);

    $response = postSagePayNotification($transaction, $fields);

    expect(sagePayBody($response->getContent())['Status'])->toBe('OK')
        ->and(app(Transactions::class)->getChildrenByTransactionId($transaction->id)[0]->status)->toBe(TransactionModel::STATUS_SUCCESS);
});

it('rejects notifications that aren’t signed with the transaction’s security key', function(Closure $fields) {
    $transaction = sagePayRegisteredTransaction();

    $response = postSagePayNotification($transaction, $fields());

    expect(sagePayBody($response->getContent()))->toBe([
        'Status' => 'INVALID',
        'RedirectURL' => CraftCms\Cms\Support\Url::siteUrl('shop/checkout'),
        'StatusDetail' => 'Invalid signature.',
    ])
        ->and(app(Transactions::class)->getChildrenByTransactionId($transaction->id))->toBeEmpty();
})->with([
    'no signature' => fn() => sagePayNotification(),
    'wrong security key' => fn() => sagePaySign(sagePayNotification(), securityKey: 'OTHERKEY12'),
    'wrong vendor' => fn() => sagePaySign(sagePayNotification(), vendor: 'OtherShop'),
    'tampered status' => fn() => array_merge(sagePaySign(sagePayNotification(['Status' => 'NOTAUTHED'])), ['Status' => 'OK']),
    'another transaction’s VendorTxCode' => fn() => sagePaySign(sagePayNotification(['VendorTxCode' => 'fedcba9876543210fedcba9876543210'])),
    'another transaction’s VPSTxId' => fn() => sagePaySign(sagePayNotification(['VPSTxId' => '{00000000-0000-0000-0000-000000000000}'])),
    'unexpected transaction type' => fn() => sagePaySign(sagePayNotification(['TxType' => 'DEFERRED'])),
]);

it('rejects notifications for unknown transactions', function() {
    $transaction = sagePayRegisteredTransaction();

    $response = postSagePayNotification($transaction, sagePaySign(sagePayNotification()), hash: 'unknown');

    expect(sagePayBody($response->getContent()))->toBe([
        'Status' => 'INVALID',
        'RedirectURL' => CraftCms\Cms\Support\Url::siteUrl(),
        'StatusDetail' => 'Transaction not found.',
    ]);
});

it('doesn’t record a second successful payment when Opayo repeats the notification', function() {
    $transaction = sagePayRegisteredTransaction();
    $fields = sagePaySign(sagePayNotification());

    postSagePayNotification($transaction, $fields);
    $response = postSagePayNotification($transaction, $fields);

    $children = app(Transactions::class)->getChildrenByTransactionId($transaction->id);
    expect($children)->toHaveCount(1)
        ->and(sagePayBody($response->getContent())['RedirectURL'])->toContain('commerceTransactionHash=' . $children[0]->hash);
});

it('only reads the transaction hash from the webhook URL', function() {
    request()->query->set('commerceTransactionHash', 'from-query');
    request()->request->set('commerceTransactionHash', 'from-body');

    expect(sagePayGateway()->getTransactionHashFromWebhook())->toBe('from-query');
});

it('doesn’t complete a payment Opayo hasn’t reported', function() {
    Http::fake();
    $transaction = sagePayRegisteredTransaction();

    $response = $transaction->getGateway()->completePurchase($transaction);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse();
    Http::assertNothingSent();
});
