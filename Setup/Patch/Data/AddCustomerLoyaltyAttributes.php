<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;

class AddCustomerLoyaltyAttributes implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var CustomerSetupFactory
     */
    private $customerSetupFactory;

    /**
     * @var AttributeSetFactory
     */
    private $attributeSetFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     * @param AttributeSetFactory $attributeSetFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory $customerSetupFactory,
        AttributeSetFactory $attributeSetFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeSetFactory = $attributeSetFactory;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerEntity = $customerSetup->getEavConfig()->getEntityType('customer');
        $attributeSetId = $customerEntity->getDefaultAttributeSetId();

        $attributeSet = $this->attributeSetFactory->create();
        $attributeGroupId = $attributeSet->getDefaultGroupId($attributeSetId);

        // Add le_current_tier attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_current_tier', [
            'type' => 'varchar',
            'label' => 'Current Loyalty Tier',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'sort_order' => 1000,
            'position' => 1000,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_current_tier')
            ->addData([
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms' => ['adminhtml_customer'],
            ]);

        $attribute->save();

        // Add le_points attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_points', [
            'type' => 'int',
            'label' => 'Loyalty Points',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'sort_order' => 1001,
            'position' => 1001,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_points')
            ->addData([
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms' => ['adminhtml_customer'],
            ]);

        $attribute->save();

        // Add le_available_coins attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_available_coins', [
            'type' => 'int',
            'label' => 'Available Loyalty Coins',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'sort_order' => 1002,
            'position' => 1002,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_available_coins')
            ->addData([
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms' => ['adminhtml_customer'],
            ]);

        $attribute->save();

        // Add le_next_tier attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_next_tier', [
            'type' => 'varchar',
            'label' => 'Next Loyalty Tier',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'sort_order' => 1003,
            'position' => 1003,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_next_tier')
            ->addData([
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms' => ['adminhtml_customer'],
            ]);

        $attribute->save();

        // Add le_points_to_next_tier attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_points_to_next_tier', [
            'type' => 'int',
            'label' => 'Points to Next Tier',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => true,
            'sort_order' => 1004,
            'position' => 1004,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_points_to_next_tier')
            ->addData([
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms' => ['adminhtml_customer'],
            ]);

        $attribute->save();

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }
}
