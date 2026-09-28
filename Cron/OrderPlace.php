<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

/** Compatibility entry point for schedules created before the outbox upgrade. */
class OrderPlace
{
    public function __construct(private DeliverEvents $delivery)
    {
    }

    public function execute(): void
    {
        $this->delivery->execute();
    }
}
