<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\CheckoutPreference;
use Magento\Framework\View\Element\Template;

class Checkout extends Template
{
    public function __construct(
        Template\Context $context,
        private CheckoutPreference $preference,
        array $data = []
    ) { parent::__construct($context, $data); }

    public function settings(): array
    {
        return $this->getLayout()->isCacheable() ? [] : $this->preference->settings();
    }
}
