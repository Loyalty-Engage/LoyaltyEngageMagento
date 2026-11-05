<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

class PersonalizationRules extends \Magento\Config\Model\Config\Backend\Serialized\ArraySerialized
{
    public function beforeSave()
    {
        $value = $this->getValue();
        $this->setValue((new \LoyaltyEngage\LoyaltyShop\Model\Personalization\Validation())->rules(is_array($value) ? $value : []));
        return parent::beforeSave();
    }
}
