<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Personalization;

use Magento\Directory\Model\CurrencyFactory;

class Amounts
{
    public function __construct(private CurrencyFactory $currencies)
    {
    }

    public function rate(string $from, string $to): ?float
    {
        if (!preg_match('/^[A-Z]{3}$/D', $from) || !preg_match('/^[A-Z]{3}$/D', $to)) {
            return null;
        }
        if ($from === $to) {
            return 1.0;
        }
        $rate = (float) $this->currencies->create()->load($from)->getRate($to);
        return is_finite($rate) && $rate > 0 ? $rate : null;
    }

    public function cart(\Magento\Quote\Model\Quote $quote, bool $includingTax, string $currency): ?float
    {
        $rate = $this->rate((string) $quote->getQuoteCurrencyCode(), $currency);
        if ($rate === null) {
            return null;
        }
        $amount = 0.0;
        foreach ($quote->getAllItems() as $item) {
            $parent = $item->getParentItem();
            if ($item->isDeleted() || ($parent && $parent->isDeleted())
                || ($parent && !$item->isChildrenCalculated())
                || ($item->getHasChildren() && $item->isChildrenCalculated())) {
                continue;
            }
            $amount += (float) ($includingTax ? ($item->getRowTotalInclTax() ?? $item->getRowTotal()) : $item->getRowTotal());
        }
        return is_finite($amount) && $amount >= 0 ? round($amount * $rate, 2) : null;
    }
}
