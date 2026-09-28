<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Quote\Model\Quote;

class CouponClaim
{
    public function __construct(private CouponOwnership $ownership, private EventOutbox $outbox, private Data $helper)
    {
    }

    public function queue(Quote $quote): void
    {
        $storeId = (int) $quote->getStoreId();
        $code = (string) $quote->getCouponCode();
        if (!$this->helper->isLoyaltyEngageEnabled($storeId) || !$quote->getCustomerEmail()
            || !$this->ownership->isManaged($code, (int) $quote->getStore()->getWebsiteId())) {
            return;
        }
        $amount = abs((float) $quote->getSubtotal() - (float) $quote->getSubtotalWithDiscount());
        if ($amount <= 0) {
            return;
        }
        $this->outbox->publish('loyaltyshop.claim_discount_event', json_encode([
            'store_id' => $storeId, 'claim_id' => $quote->getId() . ':' . $code,
            'identifier' => $this->helper->hashEmail($quote->getCustomerEmail()),
            'amount' => $amount, 'currency' => $quote->getQuoteCurrencyCode(),
        ], JSON_THROW_ON_ERROR));
    }
}
