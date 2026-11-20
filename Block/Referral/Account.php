<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Referral;

use LoyaltyEngage\LoyaltyShop\Model\Referral\Invitation;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;

class Account extends Template
{
    public function __construct(
        Template\Context $context,
        private Session $session,
        private Invitation $invitation,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getInvitationUrl(): ?string
    {
        if ($this->getLayout()->isCacheable() || !$this->session->isLoggedIn()) {
            return null;
        }
        return $this->invitation->getUrl(
            (int) $this->session->getCustomerId(),
            (int) $this->_storeManager->getStore()->getId()
        );
    }
}
