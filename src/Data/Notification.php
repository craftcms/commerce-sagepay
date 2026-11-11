<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Data;

use Illuminate\Http\Request;

/**
 * The Notification POST that Opayo sends to the gateway’s webhook URL when a Server transaction completes.
 *
 * Nothing in it can be trusted until {@see hasValidSignature()} has checked its `VPSSignature`.
 *
 * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/notification-of-transaction-result
 */
readonly class Notification
{
    /**
     * The fields that make up the signature, in order. The lowercase vendor name follows `AVSCV2`, and the
     * `SecurityKey` from the registration response follows that.
     */
    private const array SIGNATURE_FIELDS = [
        'VPSTxId',
        'VendorTxCode',
        'Status',
        'TxAuthNo',
        '{vendor}',
        'AVSCV2',
        '{securityKey}',
        'AddressResult',
        'PostCodeResult',
        'CV2Result',
        'GiftAid',
        '3DSecureStatus',
        'CAVV',
        'AddressStatus',
        'PayerStatus',
        'CardType',
        'Last4Digits',
        'DeclineCode',
        'ExpiryDate',
        'FraudResponse',
        'BankAuthCode',
        'ACSTransID',
        'DSTransID',
        'SchemeTraceID',
    ];

    /**
     * @param array<string, string> $data The posted fields
     */
    public function __construct(
        public array $data,
    ) {
    }

    /**
     * Reads the posted fields exactly as Opayo sent them.
     *
     * The raw body is parsed when there is one, because Laravel’s input middleware trims strings and converts empty
     * strings to null, and the signature has to be computed from the original values.
     */
    public static function fromRequest(Request $request): self
    {
        $body = $request->getContent();

        if ($body !== '' && str_contains((string)$request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($body, $data);
        } else {
            $data = $request->request->all();
        }

        return new self(array_map(
            fn($value) => (string)$value,
            array_filter($data, fn($value, $key) => is_string($key) && (is_scalar($value) || $value === null), ARRAY_FILTER_USE_BOTH),
        ));
    }

    public function get(string $name): ?string
    {
        return $this->data[$name] ?? null;
    }

    public function getStatus(): string
    {
        return $this->get('Status') ?? '';
    }

    /**
     * Returns the MD5 signature Opayo should have sent for these fields, in lowercase.
     */
    public function computeSignature(string $vendor, string $securityKey): string
    {
        $values = array_map(fn(string $field) => match ($field) {
            '{vendor}' => strtolower($vendor),
            '{securityKey}' => $securityKey,
            default => $this->get($field) ?? '',
        }, self::SIGNATURE_FIELDS);

        return md5(implode('', $values));
    }

    /**
     * Returns whether the posted `VPSSignature` matches the one computed with the vendor name and the transaction’s
     * secret `SecurityKey`. Opayo sends the signature in uppercase.
     */
    public function hasValidSignature(string $vendor, string $securityKey): bool
    {
        $signature = $this->get('VPSSignature');

        if ($vendor === '' || $securityKey === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals($this->computeSignature($vendor, $securityKey), strtolower($signature));
    }
}
