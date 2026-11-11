<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

class WalletRates extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('tier', ['label' => __('Loyalty Engage Tier (exact value)'), 'class' => 'required-entry']);
        $this->addColumn('percentage', ['label' => __('Earn Percentage'), 'class' => 'required-entry validate-number']);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Tier');
    }
}
