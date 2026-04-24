<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

class PreserveValue extends Value
{
    /**
     * Preserve original value if masked value is submitted
     *
     * @return \Magento\Framework\App\Config\Value
     */
    public function beforeSave()
    {
        // Prevent saving asterisks (masked value)
        if ($this->getValue() === '******') {
            $this->setValue($this->getOldValue());
        }

        return parent::beforeSave();
    }
}
