<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice;
use LoyaltyEngage\LoyaltyShop\Plugin\CaptureTierPriceExclusionPlugin;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CaptureTierPriceExclusionTest extends TestCase
{
    /** @dataProvider settingProvider */
    public function testSnapshotsTheStoreSettingWithoutAffectingOtherOptions(bool $enabled, bool $setting, bool $applied): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn($enabled);
        $helper->method('isTierPricePurchaseExclusionEnabled')->with(20)->willReturn($setting);
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(20);
        $item = $this->createMock(Item::class);
        $item->method('getQuote')->willReturn($quote);
        $result = $this->getMockBuilder(OrderItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $result->setProductOptions(['loyalty_locked_qty' => 1, AppliedTierPrice::EXCLUDED_OPTION => 1]);
        $detector = $this->createMock(AppliedTierPrice::class);
        $detector->expects($enabled && $setting ? self::once() : self::never())
            ->method('isApplied')->with($item)->willReturn($applied);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $plugin = new CaptureTierPriceExclusionPlugin($helper, $detector, $logger);
        self::assertSame($result, $plugin->afterConvert($this->createMock(ToOrderItem::class), $result, $item));
        $options = $result->getProductOptions();
        self::assertSame(1, $options['loyalty_locked_qty']);
        if ($enabled && $setting) {
            self::assertSame((int) $applied, $options[AppliedTierPrice::EXCLUDED_OPTION]);
        } else {
            self::assertArrayNotHasKey(AppliedTierPrice::EXCLUDED_OPTION, $options);
        }
    }

    public static function settingProvider(): array
    {
        return [[false, true, true], [true, false, true], [true, true, false], [true, true, true]];
    }

    public function testUnsupportedPricingFailureDoesNotBreakCheckoutOrReuseStaleExclusion(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isTierPricePurchaseExclusionEnabled')->willReturn(true);
        $item = $this->createMock(Item::class);
        $item->method('getQuote')->willReturn($this->createMock(Quote::class));
        $result = $this->getMockBuilder(OrderItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $result->setProductOptions([AppliedTierPrice::EXCLUDED_OPTION => 1]);
        $detector = $this->createMock(AppliedTierPrice::class);
        $detector->method('isApplied')->willThrowException(new \RuntimeException('Unsupported custom price model'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        (new CaptureTierPriceExclusionPlugin($helper, $detector, $logger))
            ->afterConvert($this->createMock(ToOrderItem::class), $result, $item);
        self::assertArrayNotHasKey(AppliedTierPrice::EXCLUDED_OPTION, $result->getProductOptions());
    }
}
