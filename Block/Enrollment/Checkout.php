<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Choice, Membership};
use Magento\Framework\View\Element\Template;

class Checkout extends Template
{
    public function __construct(
        Template\Context $context,
        private Config $config,
        private Choice $choice,
        private Membership $membership,
        private \Magento\Checkout\Model\Session $checkout,
        private \Magento\Customer\Model\Session $customer,
        private \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) { parent::__construct($context, $data); }

    public function settings(): array
    {
        if ($this->getLayout()->isCacheable()) { return []; }
        $storeId = (int) $this->_storeManager->getStore()->getId();
        $mode = $this->config->mode($storeId);
        if ($mode === 'disabled' || !$this->checkout->getQuoteId()) { return []; }
        if ($mode === 'required' && $this->membership->isMember((int) $this->customer->getCustomerId(), $storeId)) { return []; }
        $row = $this->choice->get((int) $this->checkout->getQuoteId(), $storeId);
        return ['label' => $this->config->label($storeId), 'mode' => $mode,
            'selected' => ($row['mode'] ?? '') === $mode && !empty($row['selected']),
            'form_key' => $this->formKey->getFormKey()];
    }
}
