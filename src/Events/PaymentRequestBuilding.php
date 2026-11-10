<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

/**
 * Dispatched before a payment or authorization is registered with Opayo. Listeners may modify `$request`, which is
 * posted as the [Transaction Registration](https://developer.elavon.com/products/en-uk/opayo-server/v1/transaction-registration)
 * request.
 */
class PaymentRequestBuilding
{
    /**
     * @param array<string, scalar|null> $request
     */
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?BasePaymentForm $form,
        public array $request,
    ) {
    }
}
