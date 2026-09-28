<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\CouponClaim;
use LoyaltyEngage\LoyaltyShop\Model\CouponOwnership;
use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;

class CouponClaimTest extends TestCase
{
    public function testOrdinaryCouponsNeverSpendLoyaltyCoins(): void
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getStore'])->addMethods(['getCouponCode', 'getCustomerEmail'])->getMock();
        $quote->method('getStoreId')->willReturn(20);
        $quote->method('getCouponCode')->willReturn('SUMMER20');
        $quote->method('getCustomerEmail')->willReturn('member@example.com');
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(2);
        $quote->method('getStore')->willReturn($store);
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $ownership = $this->createMock(CouponOwnership::class);
        $ownership->expects(self::once())->method('isManaged')->with('SUMMER20', 2)->willReturn(false);
        $outbox = $this->createMock(EventOutbox::class);
        $outbox->expects(self::never())->method('publish');
        (new CouponClaim($ownership, $outbox, $helper))->queue($quote);
    }
}
