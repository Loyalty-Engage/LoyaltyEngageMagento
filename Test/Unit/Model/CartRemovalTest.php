<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\CartRemoval;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyCartResponse;
use LoyaltyEngage\LoyaltyShop\Model\StoreContext;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CartRemovalTest extends TestCase
{
    public function testRemoveAllPreservesPaidItemsAndSavesOnlyOnce(): void
    {
        $paid = $this->createMock(Item::class);
        $loyalty = $this->createMock(Item::class);
        $loyalty->method('getId')->willReturn(42);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getAllVisibleItems', 'removeItem', 'collectTotals'])
            ->addMethods(['setTotalsCollectedFlag'])->getMock();
        $quote->method('setTotalsCollectedFlag')->willReturnSelf();
        $quote->method('getAllVisibleItems')->willReturn([$paid, $loyalty]);
        $quote->expects(self::once())->method('removeItem')->with(42)->willReturnSelf();
        $quote->method('collectTotals')->willReturnSelf();
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('isLoyaltyProduct')->willReturnCallback(static fn($item) => $item === $loyalty);
        $helper->method('successResponse')->willReturnCallback(static fn($response) => $response->setSuccess(true));
        $context = $this->createMock(StoreContext::class);
        $context->method('storeId')->willReturn(20);
        $context->method('customer')->willReturn($this->createMock(CustomerInterface::class));
        $quotes = $this->createMock(CartRepositoryInterface::class);
        $quotes->method('getActiveForCustomer')->with(7, [20])->willReturn($quote);
        $quotes->expects(self::once())->method('save')->with($quote);
        $factory = $this->createMock(LoyaltyCartResponseInterfaceFactory::class);
        $factory->method('create')->willReturn(new LoyaltyCartResponse());
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $lock->expects(self::once())->method('unlock')->with('le_cart_7');
        self::assertTrue((new CartRemoval($context, $quotes, $helper, $factory, $lock, new NullLogger()))->remove(7)->getSuccess());
    }
}
