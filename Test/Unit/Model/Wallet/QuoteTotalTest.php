<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\QuoteTotal;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\TestCase;

class QuoteTotalTest extends TestCase
{
    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
        return $quote->setStoreId(1);
    }

    private function assignment(): ShippingAssignmentInterface
    {
        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($this->createMock(Address::class));
        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);
        $assignment->method('getItems')->willReturn([new \Magento\Framework\DataObject()]);
        return $assignment;
    }

    public function testTenderChangesOnlyPayableGrandTotalAndUsesPhraseTitle(): void
    {
        $selection = $this->createMock(Selection::class);
        $selection->method('eligible')->willReturn(true);
        $selection->expects(self::once())->method('applied')->with(self::anything(), 3001)->willReturn(1000);
        $config = $this->createMock(Config::class);
        $config->method('name')->willReturn('Member Wallet');
        $collector = new QuoteTotal($selection, new Money(), $config);
        $quote = $this->quote();
        $total = new Total(['base_grand_total' => 30.005, 'grand_total' => 30.005, 'tax_amount' => 5.005], new \Magento\Framework\Serialize\Serializer\Json());
        $collector->collect($quote, $this->assignment(), $total);
        self::assertSame(20.01, $total->getBaseGrandTotal());
        self::assertSame(5.005, $total->getTaxAmount());
        $segment = $collector->fetch($quote, $total);
        self::assertInstanceOf(\Magento\Framework\Phrase::class, $segment['title']);
        self::assertSame('Member Wallet', (string) $segment['title']);
        self::assertSame(-10, $segment['value']);
    }

    public function testDisabledWalletNeverChangesOrdinaryFourDecimalGrandTotals(): void
    {
        $selection = $this->createMock(Selection::class);
        $selection->method('eligible')->willReturn(false);
        $selection->expects(self::never())->method('applied');
        $collector = new QuoteTotal($selection, new Money(), $this->createMock(Config::class));
        $total = new Total(['base_grand_total' => 30.005, 'grand_total' => 30.005], new \Magento\Framework\Serialize\Serializer\Json());
        $quote = $this->quote();
        $collector->collect($quote, $this->assignment(), $total);
        self::assertSame(30.005, $total->getBaseGrandTotal());
        self::assertSame([], $collector->fetch($quote, $total));
    }

    public function testFreshlyLoadedQuoteFetchUsesOnlySavedPresentationMetadata(): void
    {
        $selection = $this->createMock(Selection::class);
        $selection->expects(self::once())->method('preview')->willReturn(1000);
        $selection->expects(self::never())->method('reserve');
        $config = $this->createMock(Config::class);
        $config->method('name')->willReturn('Wallet');
        $segment = (new QuoteTotal($selection, new Money(), $config))->fetch($this->quote(), new Total([], new \Magento\Framework\Serialize\Serializer\Json()));
        self::assertSame(-10, $segment['value']);
    }

    public function testEmptyBillingAddressDoesNotEraseCollectedShippingTender(): void
    {
        $selection = $this->createMock(Selection::class);
        $selection->method('eligible')->willReturn(true);
        $selection->expects(self::once())->method('applied')->willReturn(1000);
        $collector = new QuoteTotal($selection, new Money(), $this->createMock(Config::class));
        $quote = $this->quote();
        $collector->collect($quote, $this->assignment(), new Total(['base_grand_total' => 30], new \Magento\Framework\Serialize\Serializer\Json()));
        $empty = $this->createMock(ShippingAssignmentInterface::class);
        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($this->createMock(Address::class));
        $empty->method('getShipping')->willReturn($shipping);
        $empty->method('getItems')->willReturn([]);
        $collector->collect($quote, $empty, new Total([], new \Magento\Framework\Serialize\Serializer\Json()));
        self::assertSame(1000, $quote->getData(Selection::AMOUNT));
        $reset = new \LoyaltyEngage\LoyaltyShop\Observer\Wallet\ResetTotals();
        $reset->execute(new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event(['quote' => $quote])]));
        $collector->collect($quote, $empty, new Total([], new \Magento\Framework\Serialize\Serializer\Json()));
        self::assertSame(0, $quote->getData(Selection::AMOUNT), 'Next empty-cart collection must clear old tender');
    }
}
