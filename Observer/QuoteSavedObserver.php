<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class QuoteSavedObserver implements ObserverInterface
{
    public function __construct(private EventOutbox $outbox)
    {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        foreach ($quote->getData('loyalty_pending_removals') ?: [] as $payload) {
            $this->outbox->publish('loyaltyshop.free_product_remove_event', json_encode($payload, JSON_THROW_ON_ERROR));
        }
        // Keep the list on the object until commit; retries are deduplicated by item ID.
    }
}
