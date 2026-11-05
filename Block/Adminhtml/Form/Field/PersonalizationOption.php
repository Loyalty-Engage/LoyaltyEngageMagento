<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Form\Field;

class PersonalizationOption extends \Magento\Framework\View\Element\Html\Select
{
    public function setInputName($value) { return $this->setName($value); }
    public function setInputId($value) { return $this->setId($value); }

    protected function _toHtml()
    {
        $options = match ($this->getData('kind')) {
            'placement' => ['manual' => 'Manual widget', 'automatic' => 'Automatic'],
            'page_scope' => ['product' => 'Product pages', 'cart' => 'Cart', 'checkout' => 'Checkout', 'pages' => 'Selected paths'],
            default => ['0' => 'No', '1' => 'Yes'],
        };
        $this->setOptions([]);
        foreach ($options as $value => $label) { $this->addOption($value, __($label)); }
        return parent::_toHtml();
    }
}
