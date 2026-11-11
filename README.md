<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="Sage Pay for Craft Commerce icon"></p>

<h1 align="center">Sage Pay for Craft Commerce</h1>

This plugin provides a [Sage Pay](https://www.elavon.co.uk/) (now Opayo by Elavon) integration for [Craft Commerce](https://craftcms.com/commerce), using the Opayo Server integration.

> [!TIP]
> This plugin uses protocol version `4.00` of the Opayo Server and Shared APIs, at the `opayo.eu.elavon.com` endpoints.

## Requirements

Sage Pay 6.x requires Craft CMS 6.0, Craft Commerce 6.0 and PHP 8.5 or later.

Sage Pay 5.x supports Craft 4 and Craft Commerce 4, or Craft 5 and Craft Commerce 5. If you’re upgrading from 5.x, see [UPGRADE.md](UPGRADE.md).

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the **Plugin Store** in your project’s control panel and search for “Sage Pay for Craft Commerce”. Then click on the **Install** button in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your project directory:
cd /path/to/my-project.test

# Require the plugin package with Composer:
composer require craftcms/commerce-sagepay

# Run the installer:
php artisan craft:plugin:install commerce-sagepay
```

## Setup

To add a Sage Pay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “Sage Pay Server”.

> [!NOTE]
> The gateway settings can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the Craft docs to learn more about using secrets in configuration.

Customers enter their card details on Opayo’s payment page. Opayo then posts the result to the gateway’s webhook URL, so your site must be reachable from Opayo’s servers.

### Using the legacy basket format

When **Send cart information** is on, the order’s line items and adjustments are sent to Opayo as the basket. Opayo has two basket formats, `Basket` and `BasketXML`. `Basket` is the legacy format, but it’s the only one the Sage 50 Accounts integration supports.

To use the legacy format, turn on **Use legacy basket format** in the gateway settings. To complete your integration with Sage 50 Accounts, give each item a product code, which is sent as `[code]` before the item’s description:

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

## Customizing payment requests

To change the Transaction Registration request sent to Opayo, listen for the `PaymentRequestBuilding` event and modify its `request` array. Its keys are Opayo’s [Transaction Registration](https://developer.elavon.com/products/en-uk/opayo-server/v1/transaction-registration) fields.

```php
use CraftCms\CommerceSagePay\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['Language'] = 'DE';
});
```
