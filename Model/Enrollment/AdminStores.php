<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Store\Model\StoreManagerInterface;

class AdminStores
{
    public function __construct(
        private StoreManagerInterface $stores,
        private Share $sharing,
        private Config $config
    ) {}

    public function forCustomer(CustomerInterface $customer): array
    {
        $stores = [];
        foreach ($this->stores->getStores() as $store) {
            $id = (int) $store->getId();
            if ($id > 0 && $store->getIsActive()
                && (!$this->sharing->isWebsiteScope() || (int) $store->getWebsiteId() === (int) $customer->getWebsiteId())
                && $this->config->mode($id) === 'required') {
                $stores[$id] = $store;
            }
        }
        return $stores;
    }
}
