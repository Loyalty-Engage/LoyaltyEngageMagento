<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Enroll};
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;

class Customer extends Template
{
    public function __construct(
        Template\Context $context,
        private Config $config,
        private Enroll $enroll,
        private Session $session,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEnrollmentLabel(): string
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();
        return $this->config->mode($storeId) === 'required' ? $this->config->label($storeId) : '';
    }

    public function getEnrollmentStatus(): string
    {
        if ($this->getLayout()->isCacheable() || !$this->session->isLoggedIn()) {
            return '';
        }
        return $this->enroll->status(
            (string) $this->session->getCustomerDataObject()->getEmail(),
            (int) $this->_storeManager->getStore()->getId()
        );
    }
}
