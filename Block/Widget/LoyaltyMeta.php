<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Widget;

use LoyaltyEngage\LoyaltyShop\Block\Account\LoyaltyMeta as AccountLoyaltyMeta;
use Magento\Widget\Block\BlockInterface;

class LoyaltyMeta extends AccountLoyaltyMeta implements BlockInterface
{
    protected $_template = 'LoyaltyEngage_LoyaltyShop::account/loyalty-meta.phtml';
}
