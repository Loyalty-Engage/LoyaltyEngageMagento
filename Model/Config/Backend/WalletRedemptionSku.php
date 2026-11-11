<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class WalletRedemptionSku extends Value
{
    public function beforeSave()
    {
        $value = $this->getValue();
        $sku = is_string($value) ? trim($value) : '';
        if (($value !== null && !is_string($value)) || ($sku !== '' && !Config::isRedemptionSku($sku))) {
            throw new LocalizedException(__('Enter a valid Loyalty Engage product SKU (1-255 letters, digits, hyphens or underscores).'));
        }
        $this->setValue($sku);
        return parent::beforeSave();
    }
}
