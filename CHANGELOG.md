# Release Notes for SagePay for Craft Commerce

## Unreleased

> [!WARNING]
> This release requires Craft Commerce 6. See [UPGRADE.md](UPGRADE.md) for the breaking changes.

- Sage Pay for Craft Commerce now requires Craft CMS 6, Craft Commerce 6 and PHP 8.5.
- The plugin now uses the Opayo Server and Shared protocol 4.00 directly, and no longer requires `craftcms/commerce-omnipay` or `omnipay/sagepay`.
- Moved all classes to the `CraftCms\CommerceSagePay` namespace. `craft\commerce\sagepay\gateways\Server` is kept as an alias of `CraftCms\CommerceSagePay\Gateway`.
- Added `CraftCms\CommerceSagePay\Events\PaymentRequestBuilding`, which replaces the Omnipay request events.
- Added `CraftCms\CommerceSagePay\Events\BasketBuilding`, which replaces the Omnipay item bag event.
- The webhook now also rejects notifications whose `VendorTxCode`, `VPSTxId` or `TxType` don’t match the transaction, and responds to notifications for unknown transactions with `Status=INVALID`.
- The basket is no longer sent when the payment currency differs from the order currency.

## 5.1.0 - 2024-03-14

- Added Craft CMS 5 and Craft Commerce 5 compatibility.

## 5.0.0 - 2022-05-04

### Added
- Added Craft CMS 4 and Craft Commerce 4 compatibility.
- All gateway settings now support environment variables.

### Removed
- Removed SagePay Direct gateway.

## 4.0.0 - 2022-03-09

### Changed
- The plugin now uses version `4.00` of the SagePay API.
- The plugin now requires PHP 7.3 or later.

## 3.0.0 - 2021-04-20

### Changed
- The plugin now requires Craft 3.6 and Commerce 3.3 or later.
- The plugin now requires Guzzle 7.

## 2.1.1 - 2020-06-17

### Added
- Added `craft\commerce\sagepay\gateways\Server::getTransactionHashFromWebhook()` to support mutex lock when processing a webhook.

## 2.1.0.3 - 2019-12-11

### Fixed
- Fixed webhook processing and response.

## 2.1.0.2 - 2019-11-08

### Fixed
- Fixed a bug where duplicate successful purchase child transactions could be recorded via the notification webhook. ([#10](https://github.com/craftcms/commerce-sagepay/issues/10))

## 2.1.0.1 - 2019-07-24

### Changed
- Updated changelog with missing changes for 2.1.0

## 2.1.0 - 2019-07-24

### Changed
- Update Craft Commerce requirements to allow for Craft Commerce 3.

## 2.0.0 - 2019-03-04

### Added
- The Vendor and Referrer ID gateway settings can now be set to environment variables.

### Changed
- SagePay for Craft Commerce now requires Craft 3.1.5 or later.
- SagePay for Craft Commerce now uses Omnipay v3.

## 1.2.0 - 2019-01-22

### Changed
- Switched to an MIT license.

### 1.1.0 - 2018-09-20

- Added support for legacy basket format. ([#3](https://github.com/craftcms/commerce-sagepay/issues/3))
- Added support for LOW profile to improve payment form display in iframes. ([#1](https://github.com/craftcms/commerce-sagepay/issues/1))

### 1.0.0

- Initial release.
