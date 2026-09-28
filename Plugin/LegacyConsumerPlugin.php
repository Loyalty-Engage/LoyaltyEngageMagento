<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;

class LegacyConsumerPlugin
{
    public function __construct(private EventOutbox $outbox)
    {
    }

    public function aroundProcess(AbstractConsumer $subject, callable $proceed, string $payloadJson): void
    {
        // Acknowledge old broker messages only after transferring them to durable storage.
        $this->outbox->publish($subject::TOPIC, $payloadJson);
    }
}
