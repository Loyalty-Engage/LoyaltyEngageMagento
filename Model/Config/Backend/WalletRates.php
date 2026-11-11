<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\Exception\LocalizedException;

class WalletRates extends ArraySerialized
{
    public function beforeSave()
    {
        try {
            (new Policy(new Money()))->rates((array) $this->getValue());
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException(__($exception->getMessage()));
        }
        return parent::beforeSave();
    }
}
