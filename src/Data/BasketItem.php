<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay\Data;

use CraftCms\Commerce\Order\LineItem\Data\LineItem;

/**
 * A line in the basket sent to Opayo. Discounts are items with a negative price.
 */
class BasketItem
{
    public function __construct(
        public string $description,
        public int|float $quantity,
        public float $price,
        /**
         * Prefixed to the description as `[code]` in the legacy basket format, for the Sage 50 Accounts integration.
         */
        public ?string $productCode = null,
        /**
         * The line item this basket item was created from, if any.
         */
        public ?LineItem $lineItem = null,
    ) {
    }
}
