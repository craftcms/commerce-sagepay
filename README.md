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

