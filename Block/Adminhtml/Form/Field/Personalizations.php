<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Form\Field;

class Personalizations extends \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
{
    private array $renderers = [];

    protected function _prepareToRender()
    {
        foreach (['key' => 'Key', 'public_id' => 'Public ID', 'enabled' => 'Enabled', 'placement' => 'Placement',
            'page_scope' => 'Page scope', 'pages' => 'Selected paths', 'personalized' => 'Personalized',
            'use_cart_value' => 'Send value'] as $name => $label) {
            $options = ['label' => __($label)];
            if (in_array($name, ['enabled', 'placement', 'page_scope', 'personalized', 'use_cart_value'], true)) {
                $this->renderers[$name] = $this->getLayout()->createBlock(PersonalizationOption::class, '',
                    ['data' => ['is_render_to_js_template' => true, 'kind' => $name]]);
                $options['renderer'] = $this->renderers[$name];
            }
            $this->addColumn($name, $options);
        }
        $this->_addAfter = false;
        $this->addColumn('embed', ['label' => __('Manual embed (copy after saving the key)')]);
        $this->_addButtonLabel = __('Add Personalization');
    }

    public function renderCellTemplate($columnName)
    {
        if ($columnName === 'embed') {
            return '<code>{{widget type="LoyaltyEngage\\LoyaltyShop\\Block\\Widget\\Personalization" key="<%- key %>"}}</code>';
        }
        return parent::renderCellTemplate($columnName);
    }

    protected function _prepareArrayRow(\Magento\Framework\DataObject $row)
    {
        $attributes = [];
        foreach ($this->renderers as $name => $renderer) {
            $attributes['option_' . $renderer->calcOptionHash($row->getData($name))] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $attributes);
    }
}
