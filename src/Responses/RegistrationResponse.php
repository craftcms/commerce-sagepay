<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Responses;

/**
 * Wraps the response to a Server transaction registration. When Opayo accepts the registration, the customer is
 * redirected to its payment page (`NextURL`). The result arrives later, in the Notification POST.
 *
 * The reference holds the registration’s `SecurityKey`, which is needed to check the notification’s signature.
 *
 * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/transaction-registration-post-response-4576
 */
class RegistrationResponse extends Response
{
    public function isSuccessful(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return in_array($this->getCode(), ['OK', 'OK REPEATED'], true) && $this->getRedirectUrl() !== '';
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectUrl(): string
    {
        return $this->data['NextURL'] ?? '';
    }
}
