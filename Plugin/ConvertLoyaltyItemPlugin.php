<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Quote\Model\Quote\Item\ToOrderItem;

class ConvertLoyaltyItemPlugin
{
    public function __construct(private Data $helper)
    {
    }

    public function afterConvert(ToOrderItem $subject, $result, $item, $data = [])
    {
        $result->setData('loyalty_locked_qty', (int) $this->helper->isLoyaltyProduct($item, true));
        return $result;
    }
}
