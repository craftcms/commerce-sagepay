<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Responses;

use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;

use function CraftCms\Cms\t;

/**
 * Wraps an Opayo response, such as the response to a `RELEASE` or `REFUND` request.
 *
 * The transaction reference is a JSON object holding the fields Opayo needs to refer to the transaction later
 * (`VendorTxCode`, `VPSTxId`, `SecurityKey` and `TxAuthNo`), in the same format the plugin has always stored.
 *
 * @see https://developer.elavon.com/products/en-uk/opayo-shared-api/v1/opayo-shared-api
 */
class Response implements RequestResponseInterface
{
    /**
     * @param array<string, string> $data The parsed Opayo response
     * @param string|null $vendorTxCode The `VendorTxCode` sent with the request
     */
    public function __construct(
        protected readonly array $data,
        protected readonly ?string $vendorTxCode = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return in_array($this->getCode(), ['OK', 'OK REPEATED', 'AUTHENTICATED', 'REGISTERED'], true);
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return false;
    }

    public function getRedirectMethod(): string
    {
        return '';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        $reference = array_filter([
            'SecurityKey' => $this->data['SecurityKey'] ?? null,
            'TxAuthNo' => $this->data['TxAuthNo'] ?? null,
            'VPSTxId' => $this->data['VPSTxId'] ?? null,
        ], fn($value) => $value !== null);

        if (empty($reference)) {
            return '';
        }

        $reference['VendorTxCode'] = $this->data['VendorTxCode'] ?? $this->vendorTxCode;

        return self::encodeReference($reference);
    }

    public function getCode(): string
    {
        return $this->data['Status'] ?? '';
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        if (isset($this->data['StatusDetail'])) {
            return $this->data['StatusDetail'];
        }

        return $this->getCode() === '' ? t('Unable to reach Sage Pay.', category: 'commerce-sagepay') : '';
    }

    /**
     * Encodes a transaction reference with its keys sorted, as the plugin has always stored them.
     *
     * @param array<string, string|null> $reference
     */
    public static function encodeReference(array $reference): string
    {
        ksort($reference);

        return (string)json_encode($reference);
    }

    /**
     * Decodes a transaction reference. Returns an empty array if it isn’t one.
     *
     * @return array<string, string>
     */
    public static function decodeReference(?string $reference): array
    {
        $decoded = json_decode((string)$reference, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_map(fn($value) => (string)$value, array_filter($decoded, fn($value) => is_scalar($value)));
    }
}
