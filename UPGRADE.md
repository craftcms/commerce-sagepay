# Upgrading to Sage Pay for Craft Commerce 6.0 (Commerce 6)

Sage Pay for Craft Commerce 6.0 supports Craft Commerce 6. It talks to the [Opayo Server](https://developer.elavon.com/products/en-uk/opayo-server/v1/api-reference-4525) and [Shared](https://developer.elavon.com/products/en-uk/opayo-shared-api/v1/opayo-shared-api) APIs (protocol 4.00) directly, rather than through Omnipay. It uses the same `opayo.eu.elavon.com` endpoints as 5.1.0.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

1. Update to Sage Pay for Craft Commerce 5.1.0 and Craft Commerce 5 first.
2. Upgrade your project to Craft 6 and Craft Commerce 6.
3. Update the plugin: `composer require craftcms/commerce-sagepay:^6.0`.
4. Review the breaking changes below.

Existing Sage Pay gateways keep working: gateways saved with the old `craft\commerce\sagepay\gateways\Server` type load as `CraftCms\CommerceSagePay\Gateway`, and save with the new class name the next time they’re saved. Their settings keep the same names and values, and transactions made with 5.x can still be captured and refunded, because transaction references are stored in the same format.

## Breaking changes

### Omnipay

The plugin no longer depends on `craftcms/commerce-omnipay`, `omnipay/sagepay` or `omnipay/common`. If your project uses Omnipay classes directly, require those packages yourself.

### Events

The Omnipay gateway events are gone.

| Before | After |
| --- | --- |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST` | `CraftCms\CommerceSagePay\Events\PaymentRequestBuilding` (transaction registrations only) |
| `craft\commerce\omnipay\base\Gateway::EVENT_AFTER_CREATE_ITEM_BAG` | `CraftCms\CommerceSagePay\Events\BasketBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_GATEWAY_REQUEST_SEND` | No replacement |

#### Changing the registration request

`PaymentRequestBuilding` is dispatched before a payment or authorization is registered. Its `$request` array is posted as the [Transaction Registration](https://developer.elavon.com/products/en-uk/opayo-server/v1/transaction-registration) request, and its keys are Opayo’s field names. Unlike `EVENT_BEFORE_SEND_PAYMENT_REQUEST`, it isn’t dispatched for `RELEASE` and `REFUND` requests.

Before:

```php
use craft\commerce\omnipay\events\SendPaymentRequestEvent;
use craft\commerce\sagepay\gateways\Server;
use yii\base\Event;

Event::on(Server::class, Server::EVENT_BEFORE_SEND_PAYMENT_REQUEST, function(SendPaymentRequestEvent $event) {
    $data = $event->requestData;
    $data['Language'] = 'DE';
    $event->modifiedRequestData = $data;
});
```

After:

```php
use CraftCms\CommerceSagePay\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['Language'] = 'DE';
});
```

#### Changing the basket

`BasketBuilding` is dispatched when **Send cart information** is on, before the basket is added to the registration. Its `$items` array holds `CraftCms\CommerceSagePay\Data\BasketItem` objects (with `description`, `quantity`, `price`, `productCode` and the `lineItem` they came from) instead of Omnipay items. Items with a negative price are sent as discounts. Setting `productCode` prefixes the description with `[code]` in the legacy basket format, for the Sage 50 Accounts integration.

Before:

```php
use craft\commerce\omnipay\base\Gateway;
use craft\commerce\omnipay\events\ItemBagEvent;
use yii\base\Event;

Event::on(Gateway::class, Gateway::EVENT_AFTER_CREATE_ITEM_BAG, function(ItemBagEvent $event) {
    $lineItems = $event->order->getLineItems();

    foreach ($event->items as $key => $item) {
        if (isset($lineItems[$key])) {
            $item->setDescription('[' . $lineItems[$key]->getSku() . ']' . $item->getDescription());
        }
    }
});
```

After:

```php
use CraftCms\CommerceSagePay\Events\BasketBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(BasketBuilding $event): void {
    foreach ($event->items as $item) {
        if ($item->lineItem) {
            $item->productCode = $item->lineItem->getSku();
        }
    }
});
```

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\sagepay\Plugin` | `CraftCms\CommerceSagePay\Plugin` |
| `craft\commerce\sagepay\gateways\Server` | `CraftCms\CommerceSagePay\Gateway` (the old name is kept as an alias) |
| `craft\commerce\sagepay\migrations\Install` | Removed |
| `craft\commerce\sagepay\migrations\m220407_165206_remove_direct_gateway_references` | Removed |

The gateway’s Omnipay methods (`populateRequest()`, `createGateway()`, `getGatewayClassName()`, `createCard()` and so on) are gone. The setting getters and setters (`getVendor()`, `getTestMode()`, `getReferrerId()`, `getUseOldBasketFormat()`, `getUseLowProfile()`, `getSendCartInfo()`, each with a `$parse` argument) are kept, and the settings are now also public properties.

### Settings

All settings keep their names: `sendCartInfo`, `vendor`, `testMode`, `referrerId`, `useOldBasketFormat` and `useLowProfile`. The settings page is now built with `settingsUi()` instead of the `commerce-sagepay/serverGatewaySettings` template, which was removed.

### Templates and Twig

The gateway has no payment form, as before: customers enter their card details on Opayo’s payment page. `gateway.getPaymentFormHtml()` still works and renders nothing.

### Webhook (Notification POST)

The webhook URL is the same. Its handling changed in these ways:

- Notifications are checked more strictly. Besides the `VPSSignature`, the `VPSTxId`, `VendorTxCode` and `TxType` must match the transaction that was registered, the transaction must belong to the gateway and still be waiting for the result, and a stored `SecurityKey` is required.
- The signature is computed from the raw posted values, so Laravel’s input trimming can’t change them, and compared in constant time.
- Notifications for unknown transactions are answered with `Status=INVALID`, redirecting the customer to the site’s home page. 5.x answered with `ok`, which Opayo treats as an error.
- The response uses the documented `RedirectURL` field name (5.x sent `RedirectUrl`), and is returned through Commerce instead of ending the request with `exit()`.

### Other changes

- The basket isn’t sent when the payment currency differs from the order currency, because Opayo checks it against the payment amount. Basket amounts are now formatted with the currency’s decimal places (`10.50` rather than `10.5`) in the XML basket too.
- If a customer reaches the completion URL before Opayo has reported the payment, the payment fails with “Sage Pay hasn’t reported the result of this payment.” instead of an exception.
