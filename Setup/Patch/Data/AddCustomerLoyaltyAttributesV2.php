<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;

class AddCustomerLoyaltyAttributesV2 implements DataPatchInterface
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

        // Add le_reserved_coins attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_reserved_coins', [
            'type'                  => 'int',
            'label'                 => 'Reserved Loyalty Coins',
            'input'                 => 'text',
            'required'              => false,
            'visible'               => true,
            'user_defined'          => true,
            'sort_order'            => 1005,
            'position'              => 1005,
            'system'                => 0,
            'is_used_in_grid'       => true,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_reserved_coins')
            ->addData([
                'attribute_set_id'   => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms'      => ['adminhtml_customer'],
            ]);

        $attribute->save();

        // Add le_expiring_points_30d attribute
        $customerSetup->addAttribute(Customer::ENTITY, 'le_expiring_points_30d', [
            'type'                  => 'int',
            'label'                 => 'Expiring Points (30 days)',
            'input'                 => 'text',
            'required'              => false,
            'visible'               => true,
            'user_defined'          => true,
            'sort_order'            => 1006,
            'position'              => 1006,
            'system'                => 0,
            'is_used_in_grid'       => true,
            'is_visible_in_grid'    => false,
            'is_filterable_in_grid' => false,
            'is_searchable_in_grid' => false,
        ]);

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'le_expiring_points_30d')
            ->addData([
                'attribute_set_id'   => $attributeSetId,
                'attribute_group_id' => $attributeGroupId,
                'used_in_forms'      => ['adminhtml_customer'],
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
        return [
            AddCustomerLoyaltyAttributes::class,
        ];
    }
}
