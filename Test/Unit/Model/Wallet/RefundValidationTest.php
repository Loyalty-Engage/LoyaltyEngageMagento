<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use LoyaltyEngage\LoyaltyShop\Plugin\Wallet\RefundValidation;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class RefundValidationTest extends TestCase
{
    public function testValidationRecognizesWalletFundsWithoutChangingCashAccounting(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
        $order->setTotalPaid(0)->setTotalRefunded(0)->setState(Order::STATE_PROCESSING);
        $tender = $this->createMock(Tender::class);
        $tender->method('get')->willReturn(['invoiced_wallet' => 2000, 'refunded_wallet' => 500]);
        $plugin = new RefundValidation($tender, $this->createMock(Documents::class));
        $result = $plugin->aroundValidate(new \stdClass(), static function ($copy) use ($order) {
            self::assertNotSame($order, $copy);
            self::assertSame(15.0, $copy->getTotalPaid());
            return [];
        }, $order);
        self::assertSame([], $result);
        self::assertSame(0, $order->getTotalPaid());
    }

    public function testNativeBlockedOrderStateIsStillValidated(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
        $order->setTotalPaid(0)->setState(Order::STATE_HOLDED);
        $tender = $this->createMock(Tender::class);
        $tender->method('get')->willReturn(['invoiced_wallet' => 2000, 'refunded_wallet' => 0]);
        $plugin = new RefundValidation($tender, $this->createMock(Documents::class));
        self::assertSame(['held'], $plugin->aroundValidate(new \stdClass(), static function ($copy) {
            self::assertSame(Order::STATE_HOLDED, $copy->getState());
            return ['held'];
        }, $order));
    }
}
