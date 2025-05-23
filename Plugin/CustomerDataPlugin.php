<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Customer\CustomerData\Customer as Subject;
use Magento\Customer\Helper\Session\CurrentCustomer;

class CustomerDataPlugin
{
    /**
     * Constructor
     *
     * @param CurrentCustomer $currentCustomer
     */
    public function __construct(
        protected CurrentCustomer $currentCustomer
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

        return $result;
    }
}
