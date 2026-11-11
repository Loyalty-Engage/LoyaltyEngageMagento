<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use LoyaltyEngage\LoyaltyShop\Plugin\Wallet\ShippingInformation;
use PHPUnit\Framework\TestCase;

class ShippingInformationTest extends TestCase
{
    public function testOnlyWalletQuotesRefreshShippingResponse(): void
    {
        foreach ([0, 1000] as $amount) {
            $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)->disableOriginalConstructor()
                ->onlyMethods(['collectTotals'])->getMock();
            $quote->expects($amount ? self::once() : self::never())->method('collectTotals')->willReturnSelf();
            $quotes = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);
            $quotes->method('getActive')->with(123)->willReturn($quote);
            $quotes->expects($amount ? self::once() : self::never())->method('save')->with($quote);
            $selection = $this->createMock(Selection::class);
            $selection->method('preview')->with($quote)->willReturn($amount);
            $selection->expects($amount ? self::once() : self::never())->method('savePreview')->with($quote);
            $totals = $this->createMock(\Magento\Quote\Api\CartTotalRepositoryInterface::class);
            $totals->expects($amount ? self::once() : self::never())->method('get')->with(123)
                ->willReturn($this->createMock(\Magento\Quote\Api\Data\TotalsInterface::class));
            $methods = $this->createMock(\Magento\Quote\Api\PaymentMethodManagementInterface::class);
            $methods->expects($amount ? self::once() : self::never())->method('getList')->with(123)->willReturn([]);
            $result = $this->createMock(\Magento\Checkout\Api\Data\PaymentDetailsInterface::class);
            self::assertSame($result, (new ShippingInformation($quotes, $selection, $totals, $methods))
                ->afterSaveAddressInformation($this->createMock(\Magento\Checkout\Model\ShippingInformationManagement::class), $result, 123));
        }
    }
}
