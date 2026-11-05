<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

class PersonalizationCurrency extends \Magento\Framework\App\Config\Value
{
    public function beforeSave()
    {
        $value = strtoupper(trim((string) $this->getValue()));
        if ($value !== '' && !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Enter the three-letter Loyalty Engage organization currency, for example EUR.'));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
