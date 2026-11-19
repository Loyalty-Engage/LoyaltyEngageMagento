<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{AdminStores, Enroll};
use Magento\Backend\Block\Template;

class Customer extends Template
{
    public function __construct(
        Template\Context $context,
        private AdminStores $stores,
        private Enroll $enroll,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStoreStatuses(): array
    {
        $customer = $this->getData('customer');
        $result = [];
        foreach ($this->stores->forCustomer($customer) as $id => $store) {
            $result[$id] = [
                'label' => $store->getName() . ' (' . $store->getCode() . ')',
                'status' => $this->enroll->status((string) $customer->getEmail(), $id),
            ];
        }
        return $result;
    }
}
