<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

class PersonalizationTenant extends \Magento\Framework\App\Config\Value
{
    public function beforeSave()
    {
        $this->setValue((new \LoyaltyEngage\LoyaltyShop\Model\Personalization\Validation())->tenant((string) $this->getValue()));
        return parent::beforeSave();
    }
}
