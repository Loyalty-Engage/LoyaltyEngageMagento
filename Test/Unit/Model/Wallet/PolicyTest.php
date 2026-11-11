<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use PHPUnit\Framework\TestCase;

class PolicyTest extends TestCase
{
    public function testRatesAndNetMerchandiseDoNotRewardWalletTender(): void
    {
        $policy = new Policy(new Money());
        $rates = $policy->rates([
            ['tier' => 'base', 'percentage' => '1'],
            ['tier' => 'gold', 'percentage' => '2'],
            '__empty' => [],
        ]);
        self::assertSame(100, $rates['base']);
        self::assertSame(200, $rates['gold']);
        self::assertSame(100, $policy->snapshot(10000, 0, 'base', $rates)['earned_cents']);
        self::assertSame(200, $policy->snapshot(10000, 0, 'gold', $rates)['earned_cents']);
        self::assertSame(160, $policy->snapshot(10000, 2000, 'gold', $rates)['earned_cents']);
        self::assertSame(167, $policy->snapshot(10000, 2000, 'gold', $rates, 12000)['earned_cents']);
        self::assertSame(0, $policy->snapshot(10000, 0, 'unknown', $rates)['earned_cents']);
        self::assertSame(0, $policy->snapshot(10000, 12000, 'gold', $rates)['earned_cents']);
    }

    public function testDuplicateTiersAreRejectedRatherThanSilentlyOverwritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Policy(new Money()))->rates([
            ['tier' => 'gold', 'percentage' => '1'], ['tier' => 'gold ', 'percentage' => '2'],
        ]);
    }

    public function testPartialReturnsUseCumulativeOriginalRewardAndFullReturnIsExact(): void
    {
        $policy = new Policy(new Money());
        self::assertSame(1, $policy->reversal(99, 2, 33));
        self::assertSame(1, $policy->reversal(99, 2, 66));
        self::assertSame(2, $policy->reversal(99, 2, 99));
        self::assertSame(2, $policy->reversal(99, 2, 100));
        self::assertSame(0, $policy->reversal(0, 0, 0));
        self::assertSame(50000000000, $policy->reversal(99999999999, 99999999999, 50000000000));
        self::assertSame(25000000000, $policy->reversal(99999999999, 49999999999, 50000000000));
        self::assertSame(250, $policy->proportion(1000, 12500, 50000));
        self::assertSame(99999999999, $policy->proportion(99999999999, 999999999999, 999999999999));
    }

    public function testRatioMatchesIntegerArithmeticAcrossSmallAmounts(): void
    {
        $policy = new Policy(new Money());
        for ($eligible = 1; $eligible <= 60; ++$eligible) {
            for ($earned = 0; $earned <= $eligible; ++$earned) {
                for ($refunded = 0; $refunded <= $eligible; ++$refunded) {
                    self::assertSame(
                        intdiv($earned * $refunded * 2 + $eligible, $eligible * 2),
                        $policy->reversal($eligible, $earned, $refunded)
                    );
                }
            }
        }
    }
}
