<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Account;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Customer\Block\Account\SortLink;

class NavigationLink extends SortLink
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\App\DefaultPathInterface $defaultPath,
        private LoyaltyHelper $loyaltyHelper,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    public function getSortOrder()
    {
        return $this->loyaltyHelper->getFrontendLoyaltyNavigationSortOrder();
    }

    public function getLabel()
    {
        return $this->loyaltyHelper->getFrontendLoyaltyNavigationLabel();
    }

    public function toHtml(): string
    {
        return $this->loyaltyHelper->isFrontendLoyaltyMetaEnabled() ? parent::toHtml() : '';
    }
}
