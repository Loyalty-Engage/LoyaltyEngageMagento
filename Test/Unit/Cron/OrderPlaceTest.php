<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Cron;

use LoyaltyEngage\LoyaltyShop\Cron\DeliverEvents;
use LoyaltyEngage\LoyaltyShop\Cron\OrderPlace;
use PHPUnit\Framework\TestCase;

class OrderPlaceTest extends TestCase
{
    public function testOldCronUsesTheSameDeliveryWorker(): void
    {
        $delivery = $this->createMock(DeliverEvents::class);
        $delivery->expects(self::once())->method('execute');
        (new OrderPlace($delivery))->execute();
    }
}
