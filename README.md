SagePay payment gateway plugin for Craft Commerce 2
=======================

This plugin provides [SagePay](https://www.sagepay.co.uk/) integrations for [Craft Commerce](https://craftcommerce.com/).

It provides both SagePay Direct and SagePay Server gateways.

## Requirements

This plugin requires Craft Commerce 2.0.0-alpha.5 or later.


## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require craftcms/commerce-sagepay

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for SagePay.

## Setup

To add a SagePay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to either “SagePay Direct” or “SagePay Server”.

### Disabling CSRF for webhooks.

You must disable CSRF protection for the incoming requests, assuming it is enabled for the site (default for Craft since 3.0).

A clean example for how to go about this can be found [here](https://craftcms.stackexchange.com/a/20301/258).

### Using the legacy basket format

SagePay has two formats for submit basket data — `Basket` and `BasketXML`. The `Basket` is a legacy format, but is the only way to integrate with Sage 50 Accounts.

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