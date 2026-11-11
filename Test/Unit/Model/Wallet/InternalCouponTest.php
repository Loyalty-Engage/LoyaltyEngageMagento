<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Plugin\Wallet\InternalCoupon;
use Magento\SalesRule\Model\{Rule, Utility};
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;

class InternalCouponTest extends TestCase
{
    public function testPurchasedWalletCodeCannotAlsoApplyAsACoupon(): void
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote->setCouponCode('LE-WALLET-' . str_repeat('a', 64));
        $address = $this->createMock(Address::class);
        $address->method('getQuote')->willReturn($quote);
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
        $plugin = new InternalCoupon();
        $utility = $this->createMock(Utility::class);
        self::assertFalse($plugin->aroundCanProcessRule($utility, static function () {
            self::fail('Internal wallet voucher must not enter the discount calculation.');
        }, $rule, $address));
        $quote->setCouponCode('SUMMER10');
        self::assertTrue($plugin->aroundCanProcessRule($utility, static fn () => true, $rule, $address));
        $quote->setCouponCode('LE-WALLET-' . str_repeat('a', 64));
        $rule->setCouponType(Rule::COUPON_TYPE_NO_COUPON);
        self::assertTrue($plugin->aroundCanProcessRule($utility, static fn () => true, $rule, $address));
    }
}
