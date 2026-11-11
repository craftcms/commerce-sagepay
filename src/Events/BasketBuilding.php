<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Events;

use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\CommerceSagePay\Data\BasketItem;

/**
 * Dispatched before the order’s basket is added to a transaction registration, when the gateway’s “Send cart
 * information” setting is on. Listeners may modify, add or remove `$items`.
 */
class BasketBuilding
{
    /**
     * @param array<int, BasketItem> $items Listeners may remove items, so the keys may not be sequential.
     */
    public function __construct(
        public readonly Transaction $transaction,
        public readonly Order $order,
        public array $items,
    ) {
    }
}
