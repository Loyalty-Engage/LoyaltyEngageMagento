<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\CartItemRemoveProcessor;
use LoyaltyEngage\LoyaltyShop\Plugin\MinimumOrderTotalsPlugin;
use LoyaltyEngage\LoyaltyShop\Plugin\MinimumOrderSubmitPlugin;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class MinimumOrderTest extends TestCase
{
    /** @dataProvider removalProvider */
    public function testTotalsAreRecollectedAtMostOnceAndTheGuardIsCleared(bool $removed): void
    {
        $processor = $this->createMock(CartItemRemoveProcessor::class);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['collectTotals'])->getMock();
        $processor->expects(self::once())->method('enforceMinimum')->with($quote)->willReturn($removed);
        $plugin = new MinimumOrderTotalsPlugin($processor);
        $quote->expects($removed ? self::once() : self::never())->method('collectTotals')
            ->willReturnCallback(fn() => $plugin->aroundCollectTotals($quote, fn() => $quote));
        self::assertSame($quote, $plugin->aroundCollectTotals($quote, fn() => $quote));
        self::assertFalse($quote->hasData('loyaltyshop_minimum_check_in_progress'));
    }

    public static function removalProvider(): array
    {
        return ['nothing removed' => [false], 'reward removed' => [true]];
    }

    public function testCheckoutWithoutApplicableRewardsDoesNotCollectOrSave(): void
    {
        $processor = $this->createMock(CartItemRemoveProcessor::class);
        $processor->method('requiresMinimumCheck')->willReturn(false);
        $quote = $this->createMock(Quote::class);
        $quote->expects(self::never())->method('collectTotals');
        $quotes = $this->createMock(CartRepositoryInterface::class);
        $quotes->expects(self::never())->method('save');
        (new MinimumOrderSubmitPlugin($processor, $quotes))->beforeSubmit($this->createMock(QuoteManagement::class), $quote);
    }

    public function testCheckoutPersistsRemovalAndRequiresReviewBeforeOrderPlacement(): void
    {
        $processor = $this->createMock(CartItemRemoveProcessor::class);
        $processor->method('requiresMinimumCheck')->willReturn(true);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'collectTotals'])->getMock();
        $quote->method('getAllVisibleItems')->willReturnOnConsecutiveCalls([new \stdClass()], []);
        $quote->expects(self::once())->method('collectTotals')->willReturnSelf();
        $quotes = $this->createMock(CartRepositoryInterface::class);
        $quotes->expects(self::once())->method('save')->with($quote);
        $this->expectException(LocalizedException::class);
        (new MinimumOrderSubmitPlugin($processor, $quotes))->beforeSubmit($this->createMock(QuoteManagement::class), $quote);
    }
}
