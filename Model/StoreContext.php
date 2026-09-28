<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class StoreContext
{
    public function __construct(
        private CustomerRepositoryInterface $customers,
        private StoreManagerInterface $stores,
        private Share $share
    ) {
    }

    public function customer(int $id): \Magento\Customer\Api\Data\CustomerInterface
    {
        $customer = $this->customers->getById($id);
        if ($this->share->isWebsiteScope()
            && (int) $customer->getWebsiteId() !== (int) $this->stores->getStore()->getWebsiteId()) {
            throw new LocalizedException(__('Customer does not belong to this website.'));
        }
        return $customer;
    }

    public function assertQuote(\Magento\Quote\Model\Quote $quote): void
    {
        if ((int) $quote->getStoreId() !== (int) $this->stores->getStore()->getId()) {
            throw new LocalizedException(__('The cart belongs to another store. Use that store endpoint.'));
        }
    }

    public function storeId(): int
    {
        return (int) $this->stores->getStore()->getId();
    }
}
