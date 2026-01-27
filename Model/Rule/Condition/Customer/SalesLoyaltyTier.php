<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer;

use Magento\Rule\Model\Condition\Context;
use Magento\Customer\Model\Customer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Http\Context as HttpContext;

class SalesLoyaltyTier extends \Magento\Rule\Model\Condition\AbstractCondition
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
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerSession $customerSession
     * @param HttpContext $httpContext
     * @param array $data
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        CustomerSession $customerSession,
        HttpContext $httpContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->httpContext = $httpContext;
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
                return 'string';
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
                return 'text';
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
        
        // For sales rules, try to get customer from quote/address
        if ($model->hasData('quote')) {
            $quote = $model->getQuote();
            if ($quote && $quote->getCustomerId()) {
                try {
                    $customer = $this->customerRepository->getById($quote->getCustomerId());
                } catch (\Exception $e) {
                    // Customer not found
                }
            }
        } elseif ($model->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($model->getCustomerId());
            } catch (\Exception $e) {
                // Customer not found
            }
        }
        
        // If no customer from model, try to get from session
        if (!$customer && $this->customerSession->isLoggedIn()) {
            try {
                $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
            } catch (\Exception $e) {
                return false;
            }
        }
        
        // If still no customer, return false
        if (!$customer) {
            return false;
        }

        // Get attribute value - handle both model and data objects
        $attributeValue = null;
        if ($customer instanceof \Magento\Customer\Model\Customer) {
            // Customer model object
            $attributeValue = $customer->getData($this->getAttribute());
        } elseif ($customer instanceof \Magento\Customer\Api\Data\CustomerInterface) {
            // Customer data object from repository
            $attributeValue = $customer->getCustomAttribute($this->getAttribute());
            if ($attributeValue) {
                $attributeValue = $attributeValue->getValue();
            }
        }
        
        // Handle null values
        if ($attributeValue === null) {
            $attributeValue = '';
        }

        return $this->validateAttribute($attributeValue);
    }

    /**
     * Get default operator input by type
     *
     * @return array
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
