<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay;

use Illuminate\Support\Facades\Http;

/**
 * A small client for the Opayo (formerly Sage Pay) Server and Shared protocol, version 4.00.
 *
 * Requests are form-encoded POSTs to `<endpoint>/<service>.vsp`, and responses are plain text `Name=Value` lines.
 *
 * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/api-reference-4525
 * @see https://developer.elavon.com/products/en-uk/opayo-shared-api/v1/opayo-shared-api
 */
class Client
{
    public const string PROTOCOL_VERSION = '4.00';

    public const string LIVE_ENDPOINT = 'https://live.opayo.eu.elavon.com/gateway/service';

    public const string TEST_ENDPOINT = 'https://sandbox.opayo.eu.elavon.com/gateway/service';

    public function __construct(
        private readonly bool $testMode = false,
    ) {
    }

    /**
     * Registers a Server transaction (`PAYMENT` or `DEFERRED`).
     *
     * @param array<string, scalar|null> $data
     * @return array<string, string>
     */
    public function register(array $data): array
    {
        return $this->send('vspserver-register', $data);
    }

    /**
     * Releases (captures) a `DEFERRED` transaction.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, string>
     */
    public function release(array $data): array
    {
        return $this->send('release', $data);
    }

    /**
     * Refunds an authorised or released transaction.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, string>
     */
    public function refund(array $data): array
    {
        return $this->send('refund', $data);
    }

    public function getEndpoint(string $service): string
    {
        return ($this->testMode ? self::TEST_ENDPOINT : self::LIVE_ENDPOINT) . '/' . $service . '.vsp';
    }

    /**
     * Parses a `Name=Value` response body, with one field per line.
     *
     * @return array<string, string>
     */
    public static function parse(string $body): array
    {
        $data = [];

        foreach (preg_split('/[\r\n]+/', $body) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            $name = trim($parts[0]);

            if ($name !== '') {
                $data[$name] = trim($parts[1] ?? '');
            }
        }

        return $data;
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array<string, string>
     */
    private function send(string $service, array $data): array
    {
        // Form encoding drops nulls, so post them as empty fields, as the plugin has always done.
        $data = array_map(fn($value) => $value ?? '', $data);

        $response = Http::asForm()->post($this->getEndpoint($service), $data);

        return self::parse($response->body());
    }
}
