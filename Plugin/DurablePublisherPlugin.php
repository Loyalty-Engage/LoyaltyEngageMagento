<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Framework\MessageQueue\PublisherInterface;

class DurablePublisherPlugin
{
    public function __construct(private EventOutbox $outbox)
    {
    }

    public function aroundPublish(PublisherInterface $subject, callable $proceed, $topicName, $data)
    {
        if (in_array($topicName, EventOutbox::TOPICS, true)) {
            $this->outbox->publish($topicName, $data);
            return null;
        }
        return $proceed($topicName, $data);
    }
}
