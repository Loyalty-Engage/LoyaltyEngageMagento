<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

class CreateLoyaltyFreeShippingRule implements DataPatchInterface
{
    /**
     * @var RuleFactory
     */
    private $ruleFactory;

    /**
     * @var RuleResource
     */
    private $ruleResource;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomerGroupCollectionFactory
     */
    private $customerGroupCollectionFactory;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RuleFactory $ruleFactory
     * @param RuleResource $ruleResource
     * @param StoreManagerInterface $storeManager
     * @param CustomerGroupCollectionFactory $customerGroupCollectionFactory
     * @param State $appState
     * @param LoggerInterface $logger
     */
    public function __construct(
        RuleFactory $ruleFactory,
        RuleResource $ruleResource,
        StoreManagerInterface $storeManager,
        CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        State $appState,
        LoggerInterface $logger
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->ruleResource = $ruleResource;
        $this->storeManager = $storeManager;
        $this->customerGroupCollectionFactory = $customerGroupCollectionFactory;
        $this->appState = $appState;
        $this->logger = $logger;
    }

    /**
     * Apply patch
     */
    public function apply()
    {
        // Set area code to avoid "Area code is not set" error
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area code already set, continue
        }

        // Check if rule already exists
        $existingRule = $this->ruleFactory->create();
        $this->ruleResource->load($existingRule, 'loyalty_free_shipping_brons', 'name');
        
        if ($existingRule->getId()) {
            // Rule already exists, skip creation
            return $this;
        }

        // Get all customer groups
        $customerGroups = $this->customerGroupCollectionFactory->create()->getAllIds();
        
        // Get all websites
        $websiteIds = array_keys($this->storeManager->getWebsites());

        /** @var Rule $rule */
        $rule = $this->ruleFactory->create();
        
        $rule->setData([
            'name' => 'Loyalty Free Shipping - Brons Tier',
            'description' => 'Free shipping for customers with Brons loyalty tier',
            'is_active' => 1,
            'website_ids' => $websiteIds,
            'customer_group_ids' => $customerGroups,
            'from_date' => null,
            'to_date' => null,
            'uses_per_customer' => 0,
            'uses_per_coupon' => 0,
            'usage_limit' => 0,
            'sort_order' => 1,
            'is_advanced' => 1,
            'simple_action' => 'free_shipping',
            'discount_amount' => 0,
            'discount_step' => 0,
            'apply_to_shipping' => 1,
            'simple_free_shipping' => 1,
            'stop_rules_processing' => 0,
        ]);

        // Set conditions - customer loyalty tier equals "Brons"
        $conditions = [
            'type' => \Magento\SalesRule\Model\Rule\Condition\Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
            'conditions' => [
                [
                    'type' => \LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer\SalesLoyaltyTier::class,
                    'attribute' => 'le_current_tier',
                    'operator' => '==',
                    'value' => 'Brons',
                    'is_value_processed' => false,
                ]
            ]
        ];

        $rule->getConditions()->setConditions([])->loadArray($conditions);

        // Set actions (no product conditions needed for free shipping)
        $actions = [
            'type' => \Magento\SalesRule\Model\Rule\Condition\Product\Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
            'conditions' => []
        ];

        $rule->getActions()->setActions([])->loadArray($actions);

        try {
            $this->ruleResource->save($rule);
        } catch (\Exception $e) {
            // Rule creation failed, but don't break the installation
            $this->logger->error('Failed to create loyalty free shipping rule: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * Get dependencies
     */
    public static function getDependencies()
    {
        return [
            AddCustomerLoyaltyAttributes::class
        ];
    }

    /**
     * Get aliases
     */
    public function getAliases()
    {
        return [];
    }
}
