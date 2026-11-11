<?php

declare(strict_types=1);

namespace CraftCms\CommerceSagePay;

use CraftCms\Commerce\Helpers\Currency;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\CommerceSagePay\Data\BasketItem;
use SimpleXMLElement;

/**
 * Builds the `BasketXML` and legacy `Basket` fields of a transaction registration.
 *
 * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/basketxml-4551
 * @see https://developer.elavon.com/products/en-uk/opayo-server/v1/basket-4550
 */
class Basket
{
    /**
     * @param list<BasketItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly string $currency,
    ) {
    }

    /**
     * Returns the order’s line items and its adjustments that aren’t included in the price, skipping zero amounts.
     */
    public static function forOrder(Order $order): self
    {
        $currency = (string)$order->currency;
        $items = [];
        $count = -1;

        foreach ($order->getLineItems() as $lineItem) {
            $price = Currency::round($lineItem->getSalePrice(), $currency);

            if ($price == 0) {
                continue;
            }

            $count++;
            $description = $lineItem->getDescription() ?: 'Item ' . $count;
            $items[] = new BasketItem($description, $lineItem->qty, $price, lineItem: $lineItem);
        }

        $count = -1;

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            $price = Currency::round($adjustment->amount, $currency);

            if ($adjustment->included || $price == 0) {
                continue;
            }

            $count++;
            $description = $adjustment->name ?: $adjustment->type . ' ' . $count;
            $items[] = new BasketItem($description, 1, $price);
        }

        return new self($items, $currency);
    }

    /**
     * Returns the basket in the `BasketXML` format, or an empty string if there are no items.
     *
     * Items with a negative price are sent as discounts.
     */
    public function toXml(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $xml = new SimpleXMLElement('<basket/>');
        $discounts = [];

        foreach ($this->items as $basketItem) {
            if ($basketItem->price < 0) {
                $discounts[] = $basketItem;
                continue;
            }

            $item = $xml->addChild('item');
            // Assigning the property, rather than using addChild(), escapes the text.
            $item->description = self::filter($basketItem->description, " +'/\\&:,.-{}");
            $item->addChild('quantity', (string)$basketItem->quantity);
            $item->addChild('unitNetAmount', $this->amount($basketItem->price));
            $item->addChild('unitTaxAmount', $this->amount(0));
            $item->addChild('unitGrossAmount', $this->amount($basketItem->price));
            $item->addChild('totalGrossAmount', $this->amount($basketItem->quantity * $basketItem->price));
        }

        if (!empty($discounts)) {
            $discountsElement = $xml->addChild('discounts');

            foreach ($discounts as $basketItem) {
                $discount = $discountsElement->addChild('discount');
                $discount->addChild('fixed', $this->amount($basketItem->quantity * $basketItem->price * -1));
                $discount->description = self::filter($basketItem->description, " +'/\\:,.-{};_@()^\"~[]$=!#?|");
            }
        }

        return (string)$xml->asXML();
    }

    /**
     * Returns the basket in the legacy `Basket` format, which is the only one the Sage 50 Accounts integration
     * supports: `lines:description:quantity:unit net:unit tax:unit gross:line total`, repeated for each line.
     */
    public function toLegacyString(): string
    {
        $basket = (string)count($this->items);

        foreach ($this->items as $basketItem) {
            $description = self::filter($basketItem->description, " +'/\\,.-{};_@()^\"~$=!#?|[]");

            if ($basketItem->productCode !== null) {
                $description = '[' . $basketItem->productCode . ']' . $description;
            }

            $basket .= ':' . implode(':', [
                $description,
                $basketItem->quantity,
                $this->amount($basketItem->price),
                $this->amount(0),
                $this->amount($basketItem->price),
                $this->amount($basketItem->quantity * $basketItem->price),
            ]);
        }

        return $basket;
    }

    private function amount(int|float $amount): string
    {
        return app(Currencies::class)->getTeller($this->currency)->convertToString($amount);
    }

    /**
     * Removes the characters Opayo doesn’t allow, and limits the text to 100 characters.
     */
    private static function filter(string $text, string $allowedSpecialChars): string
    {
        $pattern = '`[^0-9a-zA-Z' . preg_quote($allowedSpecialChars, '/') . ']`';

        return trim(substr((string)preg_replace($pattern, '', $text), 0, 100));
    }
}
