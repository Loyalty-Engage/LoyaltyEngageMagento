<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use PHPUnit\Framework\TestCase;

class RedemptionPlanTest extends TestCase
{
    public function testPlanUsesWholeEurosWithoutExceedingAnyLimit(): void
    {
        $policy = new Policy(new Money());
        self::assertSame([1000 => 1, 100 => 2], $policy->redemptionPlan(1250, 2000, 2000));
        self::assertSame([1000 => 1, 100 => 2], $policy->redemptionPlan(2000, 1250, 2000));
        self::assertSame([1000 => 1, 100 => 2], $policy->redemptionPlan(2000, 2000, 1250));
        self::assertSame([5000 => 1, 1000 => 1, 500 => 1, 100 => 3], $policy->redemptionPlan(6899, 9000, 9000));
        self::assertSame([5000 => 2], $policy->redemptionPlan(10000, 10000, 10000));
        self::assertSame([], $policy->redemptionPlan(99, 10000, 10000));
        self::assertSame([], $policy->redemptionPlan(10000, 0, 10000));
    }

    public function testPlanRejectsInvalidAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Policy(new Money()))->redemptionPlan(10000, 10000, -1);
    }
}
