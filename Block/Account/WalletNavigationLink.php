<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Account;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use Magento\Customer\Block\Account\SortLink;
use Magento\Customer\Model\Session;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Template\Context;

class WalletNavigationLink extends SortLink
{
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private Config $walletConfig,
        private Ledger $ledger,
        private Session $session,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    public function getLabel()
    {
        return $this->walletConfig->name((int) $this->_storeManager->getStore()->getId());
    }

    public function toHtml(): string
    {
        if ($this->getLayout()->isCacheable()) {
            return '';
        }
        $storeId = (int) $this->_storeManager->getStore()->getId();
        if ($this->walletConfig->enabled($storeId)) { return parent::toHtml(); }
        if (!$this->session->isLoggedIn()) { return ''; }
        $balances = $this->ledger->summary((int) $this->session->getCustomerId(), $storeId);
        return $balances['balance'] !== 0 || $balances['reserved'] > 0 ? parent::toHtml() : '';
    }
}
