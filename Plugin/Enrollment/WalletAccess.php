<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Enrollment;

class WalletAccess
{
    public function __construct(
        private \LoyaltyEngage\LoyaltyShop\Model\Enrollment\Membership $membership,
        private \Magento\Customer\Model\Session $customer,
        private \Magento\Store\Model\StoreManagerInterface $stores
    ) {}

    public function afterEligible(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection $subject, bool $result, \Magento\Quote\Model\Quote $quote): bool
    {
        return $result && $this->membership->allowsWallet((int) $quote->getCustomerId(), (int) $quote->getStoreId());
    }

    public function aroundDetails(\LoyaltyEngage\LoyaltyShop\Block\Account\Wallet $subject, callable $proceed): array
    { return $this->allowed() ? $proceed() : []; }

    public function aroundToHtml(\Magento\Framework\View\Element\AbstractBlock $subject, callable $proceed): string
    { return $this->allowed() ? $proceed() : ''; }

    private function allowed(): bool
    { return $this->membership->allowsWallet((int) $this->customer->getCustomerId(), (int) $this->stores->getStore()->getId()); }
}
