<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\CustomerData\Customer as Subject;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class CustomerDataPlugin
{
    /**
     * Constructor
     *
     * @param CurrentCustomer $currentCustomer
     */
    public function __construct(
        protected CurrentCustomer $currentCustomer,
        protected LoyaltyHelper $loyaltyHelper,
        protected CustomerRepositoryInterface $customerRepository,
        protected CustomerLoyaltyDataProvider $customerLoyaltyDataProvider,
        protected StoreManagerInterface $storeManager
    ) {
    }

    /**
     * After plugin for getSectionData
     *
     * @param Subject $subject
     * @param array $result
     * @return array
     */
    public function afterGetSectionData(Subject $subject, array $result): array
    {
        // Add customer id to the result if the customer is logged in
        if ($this->currentCustomer->getCustomerId()) {
            $result['id'] = $this->currentCustomer->getCustomerId();
        }

        if (!$this->loyaltyHelper->isFrontendLoyaltyMetaEnabled() || !$this->currentCustomer->getCustomerId()) {
            return $result;
        }

        try {
            $customer = $this->customerRepository->getById((int) $this->currentCustomer->getCustomerId());
            $storeId = (int) $this->storeManager->getStore()->getId();
            $loyaltyMeta = $this->loyaltyHelper->filterFrontendLoyaltyMeta(
                $this->customerLoyaltyDataProvider->getCustomerLoyaltyData($customer, $storeId)
            );

            $result['loyalty_meta'] = $loyaltyMeta;
            foreach ($loyaltyMeta as $key => $value) {
                $result[$key] = $value;
            }
        } catch (NoSuchEntityException) {
            return $result;
        }

        return $result;
    }
}
