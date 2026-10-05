<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Item\ToOrderItem;

class ConvertLoyaltyItemPlugin
{
    public function afterConvert(ToOrderItem $subject, $result, $item, $data = [])
    {
        $option = $item->getOptionByCode('loyalty_locked_qty');
        if (!$option || (string) $option->getValue() !== '1') {
            return $result;
        }

        $productOptions = $result->getProductOptions() ?: [];
        $productOptions['loyalty_locked_qty'] = 1;
        $result->setProductOptions($productOptions);
        return $result;
    }
}
