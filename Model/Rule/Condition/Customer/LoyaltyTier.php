<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer;

use Magento\Rule\Model\Condition\Context;
use Magento\Customer\Model\Customer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Http\Context as HttpContext;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyTier extends \Magento\Rule\Model\Condition\AbstractCondition
{
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var HttpContext
     */
    private $httpContext;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerSession $customerSession
     * @param HttpContext $httpContext
     * @param LoyaltyHelper $loyaltyHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        CustomerSession $customerSession,
        HttpContext $httpContext,
        LoyaltyHelper $loyaltyHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->httpContext = $httpContext;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Load attribute options
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $attributes = [
            'le_current_tier' => __('Current Loyalty Tier'),
            'le_points' => __('Loyalty Points'),
            'le_available_coins' => __('Available Loyalty Coins'),
            'le_next_tier' => __('Next Loyalty Tier'),
            'le_points_to_next_tier' => __('Points to Next Tier')
        ];

        $this->setAttributeOption($attributes);
        return $this;
    }

    /**
     * Get input type
     *
     * @return string
     */
    public function getInputType()
    {
        switch ($this->getAttribute()) {
            case 'le_current_tier':
            case 'le_next_tier':
                return 'string'; // Changed from 'select' to 'string' for contains/equals operators
            case 'le_points':
            case 'le_available_coins':
            case 'le_points_to_next_tier':
                return 'numeric';
        }
        return 'string';
    }

    /**
     * Get value element type
     *
     * @return string
     */
    public function getValueElementType()
    {
        switch ($this->getAttribute()) {
            case 'le_current_tier':
            case 'le_next_tier':
                return 'text'; // Changed from 'select' to 'text' for manual input
            case 'le_points':
            case 'le_available_coins':
            case 'le_points_to_next_tier':
                return 'text';
        }
        return 'text';
    }

    /**
     * Get value select options
     *
     * @return array
     */
    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            switch ($this->getAttribute()) {
                case 'le_current_tier':
                case 'le_next_tier':
                    $options = [
                        ['value' => '', 'label' => __('Please select...')],
                        ['value' => 'Brons', 'label' => __('Brons')],
                        ['value' => 'Zilver', 'label' => __('Zilver')],
                        ['value' => 'Goud', 'label' => __('Goud')],
                        ['value' => 'Platina', 'label' => __('Platina')]
                    ];
                    break;
                default:
                    $options = [];
            }
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }

    /**
     * Validate customer attribute against the rule
     *
     * @param AbstractModel $model
     * @return bool
     */
    public function validate(AbstractModel $model)
    {
        $customer = null;

        $this->loyaltyHelper->log(
            'debug',
            'LoyaltyTier',
            'validate_start',
            'Starting validation for loyalty tier condition',
            ['attribute' => $this->getAttribute()]
        );
        
        // Try to get customer from the model first
        if ($model instanceof Customer) {
            $customer = $model;
            $this->loyaltyHelper->log(
                'debug',
                'LoyaltyTier',
                'customer_from_model',
                'Customer loaded directly from model',
                ['customer_id' => $customer->getId()]
            );
        } elseif ($model->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($model->getCustomerId());
                $this->loyaltyHelper->log(
                    'debug',
                    'LoyaltyTier',
                    'customer_from_repository',
                    'Customer loaded via repository using model customer ID',
                    ['customer_id' => $model->getCustomerId()]
                );
            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'LoyaltyTier',
                    'customer_load_failed',
                    'Failed to load customer from repository',
                    ['exception' => $e->getMessage()]
                );
            }
        }
        
        // If no customer from model, try to get from session (for catalog rules)
        if (!$customer && $this->customerSession->isLoggedIn()) {
            try {
                $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
                $this->loyaltyHelper->log(
                    'debug',
                    'LoyaltyTier',
                    'customer_from_session',
                    'Customer loaded from session',
                    ['customer_id' => $this->customerSession->getCustomerId()]
                );
            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'LoyaltyTier',
                    'session_customer_load_failed',
                    'Failed to load customer from session',
                    ['exception' => $e->getMessage()]
                );
                return false;
            }
        }
        
        // If still no customer, return false
        if (!$customer) {
            $this->loyaltyHelper->log(
                'info',
                'LoyaltyTier',
                'no_customer',
                'No customer found for validation'
            );
            return false;
        }
        $attributeCode = $this->getAttribute();
        $attributeValue = $customer->getData($attributeCode);
        
        // Handle null values
        if ($attributeValue === null) {
            $attributeValue = '';
        }
        $this->loyaltyHelper->log(
            'debug',
            'LoyaltyTier',
            'attribute_value',
            'Fetched customer attribute value',
            [
                'customer_id' => $customer->getId(),
                'attribute' => $attributeCode,
                'value' => $attributeValue
            ]
        );

        $result = $this->validateAttribute($attributeValue);

        $this->loyaltyHelper->log(
            'debug',
            'LoyaltyTier',
            'validation_result',
            'Validation result computed',
            [
                'attribute' => $attributeCode,
                'value' => $attributeValue,
                'result' => $result
            ]
        );

        return $result;
    }

    /**
     * Collect validated attributes for catalog rule
     *
     * This method is required for catalog rules to work properly.
     * Since we're validating customer attributes (not product attributes),
     * we don't need to add any product attributes to the collection.
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $productCollection
     * @return $this
     */
    public function collectValidatedAttributes($productCollection)
    {
        // Customer loyalty attributes are not product attributes,
        // so we don't need to add anything to the product collection.
        // This method is required to prevent "Invalid method" errors.
        return $this;
    }

    /**
     * Get condition label
     *
     * @return \Magento\Framework\Phrase
     */
    public function getDefaultOperatorInputByType()
    {
        if (null === $this->_defaultOperatorInputByType) {
            $this->_defaultOperatorInputByType = [
                'string' => ['==', '!=', '>=', '>', '<=', '<', '{}', '!{}'],
                'numeric' => ['==', '!=', '>=', '>', '<=', '<'],
                'date' => ['==', '>=', '<='],
                'select' => ['==', '!='],
                'boolean' => ['==', '!='],
                'multiselect' => ['{}', '!{}', '()', '!()'],
                'grid' => ['()', '!()'],
            ];
        }
        return $this->_defaultOperatorInputByType;
    }
}
