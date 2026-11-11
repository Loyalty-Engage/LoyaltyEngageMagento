<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Account;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;

class Wallet extends Template
{
    private ?array $details = null;

    public function __construct(
        Template\Context $context,
        private Session $session,
        private Config $config,
        private Ledger $ledger,
        private Money $money,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function details(): array
    {
        if ($this->getLayout()->isCacheable() || !$this->session->isLoggedIn()) {
            return [];
        }
        if ($this->details !== null) {
            return $this->details;
        }
        $customerId = (int) $this->session->getCustomerId();
        $storeId = (int) $this->_storeManager->getStore()->getId();
        $balances = $this->ledger->summary($customerId, $storeId);
        $balance = $balances['balance'];
        if (!$this->config->enabled($storeId) && $balance === 0 && $balances['reserved'] === 0) {
            return $this->details = [];
        }
        return $this->details = [
            'name' => $this->config->name($storeId), 'balance' => $this->money->decimal($balance),
            'available' => $this->money->decimal($balances['available']),
            'reserved' => $this->money->decimal($balances['reserved']),
            'negative' => $balance < 0,
            'spending' => $this->config->spending($storeId),
            'history' => array_map(fn(array $row): array => $row + [
                'amount' => $this->money->decimal((int) $row['amount_cents']),
            ], $this->ledger->history($customerId, $storeId)),
        ];
    }
}
