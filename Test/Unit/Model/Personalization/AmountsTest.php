<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Personalization;

use LoyaltyEngage\LoyaltyShop\Model\Personalization\Amounts;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\Currency;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

class AmountsTest extends TestCase
{
    private function item(float $excl, float $incl, bool $deleted = false, ?Item $parent = null, bool $childrenCalculated = false): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'isChildrenCalculated'])->getMock();
        $item->method('getParentItem')->willReturn($parent);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->setRowTotal($excl)->setRowTotalInclTax($incl)->setDiscountAmount(10);
        $item->isDeleted($deleted);
        return $item;
    }

    public function testTaxBasisCurrencyAndPricingChildrenAreCountedOnceBeforeCouponDiscount(): void
    {
        $factory = $this->createMock(CurrencyFactory::class);
        $currency = $this->createMock(Currency::class);
        $currency->method('load')->willReturnSelf();
        $currency->method('getRate')->with('EUR')->willReturn(0.8);
        $factory->method('create')->willReturn($currency);
        $simple = $this->item(20, 24.2);
        $parent = $this->item(30, 36.3);
        $child = $this->item(30, 36.3, false, $parent);
        $bundle = $this->item(999, 999, false, null, true)->setHasChildren(true);
        $selection = $this->item(10, 12.1, false, $bundle, true);
        $deleted = $this->item(100, 121, true);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getAllItems', 'collectTotals'])->getMock();
        $quote->expects(self::never())->method('collectTotals');
        $quote->setQuoteCurrencyCode('USD');
        $quote->method('getAllItems')->willReturn([$simple, $parent, $child, $bundle, $selection, $deleted]);
        $amounts = new Amounts($factory);
        self::assertSame(48.0, $amounts->cart($quote, false, 'EUR'));
        self::assertSame(58.08, $amounts->cart($quote, true, 'EUR'));
    }

    public function testMissingRateOrInvalidCurrencyNeverGuessesAnAmount(): void
    {
        $factory = $this->createMock(CurrencyFactory::class);
        $currency = $this->createMock(Currency::class);
        $currency->method('load')->willReturnSelf();
        $currency->method('getRate')->willReturn(false);
        $factory->method('create')->willReturn($currency);
        $amounts = new Amounts($factory);
        self::assertNull($amounts->rate('USD', 'EUR'));
        self::assertNull($amounts->rate('EUR', ''));
        self::assertSame(1.0, $amounts->rate('EUR', 'EUR'));
    }
}
