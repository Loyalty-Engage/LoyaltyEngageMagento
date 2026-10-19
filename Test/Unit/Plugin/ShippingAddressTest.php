<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Plugin\Quote\AddressPlugin;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ShippingAddressTest extends TestCase
{
    public function testQualifyingQuoteGetsZeroRateWithoutChangingCarrierCost(): void
    {
        $quote = $this->createMock(Quote::class);
        $checker = $this->createMock(LoyaltyTierChecker::class);
        $checker->method('qualifiesQuote')->with($quote)->willReturn(true);
        $rate = new DataObject(['price' => 12.5, 'cost' => 8, 'carrier' => 'custom', 'method' => 'express']);
        $address = $this->getMockBuilder(Address::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getShippingRatesCollection'])->getMock();
        $address->method('getQuote')->willReturn($quote);
        $address->method('getShippingRatesCollection')->willReturn([$rate]);
        $plugin = new AddressPlugin($checker, $this->createMock(Data::class), new NullLogger());
        self::assertSame($address, $plugin->afterCollectShippingRates($address, $address));
        self::assertEquals(0, $rate->getPrice());
        self::assertSame(8, $rate->getCost());
        self::assertSame(1, $address->getData('loyalty_free_shipping_applied'));
    }

    public function testLossOfEligibilityForcesRateRecollectionAndClearsMarker(): void
    {
        $checker = $this->createMock(LoyaltyTierChecker::class);
        $checker->method('qualifiesQuote')->willReturn(false);
        $address = $this->getMockBuilder(Address::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])->getMock();
        $address->method('getQuote')->willReturn($this->createMock(Quote::class));
        $address->setData('loyalty_free_shipping_applied', 1);
        $plugin = new AddressPlugin($checker, $this->createMock(Data::class), new NullLogger());
        $plugin->beforeCollectShippingRates($address);
        self::assertTrue($address->getCollectShippingRates());
        $plugin->afterCollectShippingRates($address, $address);
        self::assertSame(0, $address->getData('loyalty_free_shipping_applied'));
    }

    /** @dataProvider collectionProvider */
    public function testRateCollectionOnlyRunsForTransitionOrMagentoInvalidation(bool $applied, bool $eligible,
        bool $invalidated, bool $expected): void
    {
        $checker = $this->createMock(LoyaltyTierChecker::class);
        $checker->expects(self::once())->method('qualifiesQuote')->willReturn($eligible);
        $address = $this->getMockBuilder(Address::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuote', 'getShippingRatesCollection'])->getMock();
        $address->method('getQuote')->willReturn($this->createMock(Quote::class));
        $rate = new DataObject(['price' => $applied ? 0 : 10]);
        $address->method('getShippingRatesCollection')->willReturn([$rate]);
        $address->setData('loyalty_free_shipping_applied', (int) $applied)->setCollectShippingRates($invalidated);
        $plugin = new AddressPlugin($checker, $this->createMock(Data::class), new NullLogger());
        $plugin->beforeCollectShippingRates($address);
        self::assertSame($expected, (bool) $address->getCollectShippingRates());
        if ($expected) {
            $rate->setPrice(10); // Simulated carrier response.
        }
        $address->setCollectShippingRates(false);
        $plugin->afterCollectShippingRates($address, $address);
        self::assertEquals($eligible ? 0 : 10, $rate->getPrice());
        self::assertSame($eligible, (bool) $address->getData('loyalty_free_shipping_applied'));
    }

    public static function collectionProvider(): array
    {
        return [
            'unchanged qualifying customer' => [true, true, false, false],
            'unchanged nonmember' => [false, false, false, false],
            'qualification gained' => [false, true, false, true],
            'qualification lost' => [true, false, false, true],
            'address or cart invalidated by Magento' => [true, true, true, true],
        ];
    }
}
