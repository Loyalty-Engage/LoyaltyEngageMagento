<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;

class LoyaltyTierChecker
{
    public function __construct(
        ApiClient $apiClient,
        private CustomerRepositoryInterface $customerRepository,
        private Data $loyaltyHelper,
        private CustomerLoyaltyDataProvider $customerLoyaltyDataProvider
    ) {
    }

    public function qualifiesQuote(\Magento\Quote\Model\Quote $quote): bool
    {
        $storeId = (int) $quote->getStoreId();
        if (!$quote->getCustomerId() || $quote->getCustomerIsGuest()
            || !$this->loyaltyHelper->isLoyaltyEngageEnabled($storeId)
            || !$this->loyaltyHelper->isFreeShippingEnabled($storeId)) {
            return false;
        }
        $customer = $this->customerRepository->getById((int) $quote->getCustomerId());
        $tier = $this->customerLoyaltyDataProvider->getAttributeValue($customer, 'le_current_tier', $storeId);
        return $tier !== null && in_array((string) $tier, $this->loyaltyHelper->getFreeShippingTiersArray($storeId), true);
    }

    public function qualifiesForFreeShipping(?string $customerEmail = null): bool
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isFreeShippingEnabled()) {
            return false;
        }
        $customerEmail = $customerEmail ?: $this->loyaltyHelper->getCustomerEmail();
        if (!$customerEmail) {
            return false;
        }
        $tier = $this->getCustomerTier($customerEmail);
        return $tier !== null && in_array($tier, $this->loyaltyHelper->getFreeShippingTiersArray(), true);
    }

    public function getCustomerTier(string $customerEmail): ?string
    {
        $customer = $this->customerLoyaltyDataProvider->getCustomerByEmail($customerEmail);
        if (!$customer) {
            return null;
        }
        $tier = $this->customerLoyaltyDataProvider->getAttributeValue($customer, 'le_current_tier');
        return $tier !== null && $tier !== '' ? (string) $tier : null;
    }
}
