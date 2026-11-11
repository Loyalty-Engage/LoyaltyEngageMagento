<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

/** EUR accounting amounts. Never derive cents by multiplying a binary float. */
class Money
{
    public const MAX_CENTS = 99999999999;

    /** Convert Magento's native 4-decimal totals at the system boundary only. */
    public function native($amount): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            throw new \InvalidArgumentException('Invalid native wallet total.');
        }
        return $this->cents(number_format((float) $amount, 4, '.', ''));
    }

    public function cents(string $amount): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,4}))?\z/D', $amount, $match)) {
            throw new \InvalidArgumentException('Invalid non-negative EUR amount.');
        }
        $fraction = str_pad($match[2] ?? '', 4, '0');
        $cents = (int) $match[1] * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$cents;
        }
        $this->assertCents($cents);
        return $cents;
    }

    public function decimal(int $cents): string
    {
        $this->assertCents($cents, true);
        $absolute = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($absolute, 100) . '.'
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function basisPoints(string $percentage): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]?|100)(?:\.([0-9]{1,2}))?\z/D', $percentage, $match)) {
            throw new \InvalidArgumentException('Wallet percentages must be between 0 and 100, with at most 2 decimals.');
        }
        $value = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        if ($value > 10000) {
            throw new \InvalidArgumentException('Wallet percentage exceeds 100.');
        }
        return $value;
    }

    public function quantityUnits(string $quantity): int
    {
        if (!preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?\z/D', $quantity, $match)) {
            throw new \InvalidArgumentException('Invalid order quantity.');
        }
        return (int) $match[1] * 10000 + (int) str_pad($match[2] ?? '', 4, '0');
    }

    public function percentage(int $cents, int $basisPoints): int
    {
        $this->assertCents($cents);
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new \InvalidArgumentException('Invalid wallet rate.');
        }
        return intdiv($cents * $basisPoints + 5000, 10000);
    }

    public function assertCents(int $cents, bool $signed = false): void
    {
        if ($cents > self::MAX_CENTS || $cents < ($signed ? -self::MAX_CENTS : 0)) {
            throw new \InvalidArgumentException('Wallet amount is outside the supported range.');
        }
    }
}
