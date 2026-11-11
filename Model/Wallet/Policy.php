<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

/** Pure policy; callers provide historical merchandise net of discounts. */
class Policy
{
    public function __construct(private Money $money)
    {
    }

    public function rates(array $rows): array
    {
        $rates = [];
        foreach ($rows as $key => $row) {
            if ($key === '__empty') {
                continue;
            }
            if (!is_array($row) || !is_string($row['tier'] ?? null)
                || !is_string($row['percentage'] ?? null)) {
                throw new \InvalidArgumentException('A tier and percentage are required for each wallet rule.');
            }
            $tier = trim($row['tier']);
            if ($tier === '' || mb_strlen($tier) > 255 || preg_match('/[\x00-\x1f\x7f]/', $tier)
                || array_key_exists($tier, $rates)) {
                throw new \InvalidArgumentException('Wallet tiers must be non-empty, unique and valid.');
            }
            $rates[$tier] = $this->money->basisPoints(trim($row['percentage']));
        }
        return $rates;
    }

    /** @return array<int, int> Denomination in cents => quantity, largest first. */
    public function redemptionPlan(int $requestedCents, int $availableCents, int $payableCents): array
    {
        foreach ([$requestedCents, $availableCents, $payableCents] as $amount) {
            $this->money->assertCents($amount);
        }
        $remaining = intdiv(min($requestedCents, $availableCents, $payableCents), 100) * 100;
        $plan = [];
        foreach ([5000, 1000, 500, 100] as $denomination) {
            $quantity = intdiv($remaining, $denomination);
            if ($quantity > 0) {
                $plan[$denomination] = $quantity;
                $remaining %= $denomination;
            }
        }
        return $plan;
    }

    public function snapshot(
        int $merchandiseCents,
        int $walletPaidCents,
        string $tier,
        array $rates,
        ?int $orderGrossCents = null
    ): array {
        $this->money->assertCents($merchandiseCents);
        $this->money->assertCents($walletPaidCents);
        $gross = $orderGrossCents ?? $merchandiseCents;
        $this->money->assertCents($gross);
        // Use the same tender proportion as invoices/refunds. Allocating all
        // wallet spend to the first SKU would overreward some partial returns.
        $eligible = $walletPaidCents === 0 ? $merchandiseCents : ($gross > 0
            ? $this->proportion($merchandiseCents, max(0, $gross - $walletPaidCents), $gross) : 0);
        $rate = $rates[$tier] ?? 0;
        if (!is_int($rate)) {
            throw new \InvalidArgumentException('Invalid wallet rate snapshot.');
        }
        return [
            'tier' => $tier,
            'rate_bps' => $rate,
            'eligible_cents' => $eligible,
            'earned_cents' => $this->money->percentage($eligible, $rate),
        ];
    }

    public function reversal(int $originalEligibleCents, int $originalEarnedCents, int $refundedEligibleCents): int
    {
        foreach ([$originalEligibleCents, $originalEarnedCents, $refundedEligibleCents] as $amount) {
            $this->money->assertCents($amount);
        }
        if ($originalEarnedCents > $originalEligibleCents) {
            throw new \InvalidArgumentException('Earned credit exceeds eligible merchandise.');
        }
        if ($originalEligibleCents === 0) {
            return 0;
        }
        return $this->proportion($originalEarnedCents, min($originalEligibleCents, $refundedEligibleCents), $originalEligibleCents);
    }

    public function proportion(int $cents, int $numerator, int $denominator): int
    {
        $this->money->assertCents($cents);
        if ($denominator < 1 || $denominator > 999999999999 || $numerator < 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException('Invalid historical wallet allocation.');
        }
        // Avoid a large cents*cents product: use quotient and remainder separately.
        $whole = intdiv($cents, $denominator) * $numerator;
        $remainder = $cents % $denominator;
        // Binary floats are deliberately not used for monetary ratios.
        return $whole + $this->roundedRatio($remainder, $numerator, $denominator);
    }

    private function roundedRatio(int $a, int $b, int $denominator): int
    {
        $quotient = 0;
        $remainder = 0;
        // Long multiplication with modular reduction, bounded by 37 bits for cents.
        foreach (str_split(decbin($b)) as $bit) {
            $remainder = $remainder * 2 + ($bit === '1' ? $a : 0);
            $quotient = $quotient * 2 + intdiv($remainder, $denominator);
            $remainder %= $denominator;
        }
        return $quotient + ($remainder * 2 >= $denominator ? 1 : 0);
    }
}
