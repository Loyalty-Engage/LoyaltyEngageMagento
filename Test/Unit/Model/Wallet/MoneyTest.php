<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testCentAmountsAndBasisPointsAreExact(): void
    {
        $money = new Money();
        self::assertSame(101, $money->cents('1.0050'));
        self::assertSame(100, $money->cents('1.0049'));
        self::assertSame(10, $money->cents('0.10'));
        self::assertSame(99999999999, $money->cents('999999999.99'));
        self::assertSame('0.00', $money->decimal(0));
        self::assertSame('-20.01', $money->decimal(-2001));
        self::assertSame(125, $money->basisPoints('1.25'));
        self::assertSame(10000, $money->basisPoints('100.00'));
        self::assertSame(1, $money->percentage(50, 100));
        self::assertSame(99999999999, $money->percentage(99999999999, 10000));
        self::assertSame(12500, $money->quantityUnits('1.2500'));
        self::assertSame(1, $money->quantityUnits('0.0001'));
    }

    public function testInvalidMoneyAndRatesFailClosed(): void
    {
        $money = new Money();
        foreach (['-1', '1e3', '1,00', 'NaN', ' 1', '01', '1.00001', '999999999.9999'] as $amount) {
            try {
                $money->cents($amount);
                self::fail('Accepted invalid amount: ' . $amount);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach (['100.01', '101', '1.001', '-1', '1,5', 'NaN'] as $rate) {
            try {
                $money->basisPoints($rate);
                self::fail('Accepted invalid rate: ' . $rate);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
