<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Sage Pay for Craft Commerce icon"></p>

<h1 align="center">Sage Pay for Craft Commerce</h1>

This plugin provides a [Sage Pay](https://www.sagepay.co.uk/) integration for [Craft Commerce](https://craftcms.com/commerce).

> **Tip:** This plugin uses protocol version `4.00` of the API for SagePay/Opayo.

## Requirements

This plugin requires Craft 3.6, Craft Commerce 3.3 and PHP 7.3 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for Sage Pay for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/commerce-sagepay

# tell Craft to install the plugin
./craft install/plugin commerce-sagepay
```

## Setup

To add a Sage Pay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to either “Sage Pay Direct” or “Sage Pay Server”.

> **Tip:** The Vendor and Referrer ID gateway settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.

### Using the legacy basket format

Sage Pay has two formats for submit basket data — `Basket` and `BasketXML`. The `Basket` is a legacy format, but is the only way to integrate with Sage 50 Accounts.

To use the legacy format, simply turn on the appropriate setting in the gateway settings. To complete your integration with Sage 50 Accounts you can use the following event:

```php
use \craft\commerce\omnipay\base\Gateway as BaseGateway;

Event::on(BaseGateway::class, BaseGatewa::EVENT_AFTER_CREATE_ITEM_BAG, function(ItemBagEvent $itemBagEvent) {
    
    $orderLineItems = $itemBagEvent->order->getLineItems();

    /**
     * @var $item Item
    */
    foreach ($itemBagEvent->items as $key => $item) {

        if (!isset($orderLineItems[$key])) {
            return;
        }

        $orderLineItem  = $orderLineItems[$key];

        // Make sure that the description and price are the same as we are relying upon the order
        // of the Order Items and The OmniPay Item Bag to be the same
        if ($orderLineItem->getDescription() != $item->getDescription()) {
            return;
        }

        if ($orderLineItem->price != $item->getPrice()) {
            return;
        }

        $sku = $orderLineItem->getSku();

        // Place the SKU within [] as the Product Record for the Sage 50 Accounts Integration
        $description = '[' . $sku . ']' . $item->getDescription();
        $item->setDescription($description);
    }
});
```

### Testing 3D Secure with Direct integration

It is worth noting that if you are using the direct integration and you are testing 3D Secure, you will need to make sure you are running your site with HTTPS.

You must also have the Craft config option [sameSiteCookieValue](https://craftcms.com/docs/3.x/config/config-settings.html#samesitecookievalue) set to `'None'` in your general config.