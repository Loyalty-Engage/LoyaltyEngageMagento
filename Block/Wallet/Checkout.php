<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Quote\Api\CartRepositoryInterface;

class Checkout extends Template
{
    public function __construct(
        Template\Context $context,
        private Config $config,
        private Ledger $ledger,
        private Money $money,
        private Selection $selection,
        private Session $customer,
        private CheckoutSession $checkout,
        private CartRepositoryInterface $quotes,
        private FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function wallet(): array
    {
        if ($this->getLayout()->isCacheable() || !$this->customer->isLoggedIn() || !$this->checkout->getQuoteId()) {
            return [];
        }
        try {
            $quote = $this->quotes->getActive((int) $this->checkout->getQuoteId(), [(int) $this->_storeManager->getStore()->getId()]);
        } catch (NoSuchEntityException) {
            return [];
        }
        if (!$quote instanceof \Magento\Quote\Model\Quote || !$this->selection->eligible($quote)
            || (int) $quote->getCustomerId() !== (int) $this->customer->getCustomerId()) {
            return [];
        }
        $available = $this->ledger->available((int) $quote->getCustomerId(), (int) $quote->getStoreId());
        $available = $this->config->payable($available, (int) $quote->getStoreId());
        $row = $this->selection->row((int) $quote->getId());
        return [
            'name' => $this->config->name((int) $quote->getStoreId()),
            'coins_required' => $this->config->coinsRequired((int) $quote->getStoreId()),
            'available' => $this->money->decimal($available),
            'requested' => $this->money->decimal(min($available,
                (int) ($row['store_id'] ?? 0) === (int) $quote->getStoreId() ? (int) ($row['requested_cents'] ?? 0) : 0)),
            'form_key' => $this->formKey->getFormKey(),
            'return_to' => str_contains((string) $this->getRequest()->getFullActionName(), 'cart') ? 'cart' : 'checkout',
        ];
    }
}
