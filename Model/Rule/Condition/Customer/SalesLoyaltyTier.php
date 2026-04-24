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

        // From quote (sales rules)
        if ($model->hasData('quote')) {
            $quote = $model->getQuote();

            if ($quote && $quote->getCustomerId()) {
                try {
                    $customer = $this->customerRepository->getById($quote->getCustomerId());

                } catch (\Exception $e) {
                    $this->loyaltyHelper->log(
                        'error',
                        'SalesLoyaltyTier',
                        'quote_customer_error',
                        'Failed to load customer from quote',
                        ['exception' => $e->getMessage()]
                    );
                }
            }
        } elseif ($model->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($model->getCustomerId());

            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'SalesLoyaltyTier',
                    'model_customer_error',
                    'Failed to load customer from model',
                    ['exception' => $e->getMessage()]
                );
            }
        }

        // From session fallback
        if (!$customer && $this->customerSession->isLoggedIn()) {
            try {
                $customer = $this->customerRepository->getById(
                    $this->customerSession->getCustomerId()
                );

            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'SalesLoyaltyTier',
                    'session_customer_error',
                    'Failed to load customer from session',
                    ['exception' => $e->getMessage()]
                );
                return false;
            }
        }

        // No customer
        if (!$customer) {
            $this->loyaltyHelper->log(
                'info',
                'SalesLoyaltyTier',
                'no_customer',
                'No customer found during validation'
            );
            return false;
        }

        $attributeCode = $this->getAttribute();
        $attributeValue = null;

        // Handle both types
        if ($customer instanceof \Magento\Customer\Model\Customer) {
            $attributeValue = $customer->getData($attributeCode);

        } elseif ($customer instanceof \Magento\Customer\Api\Data\CustomerInterface) {
            $attribute = $customer->getCustomAttribute($attributeCode);

            if ($attribute) {
                $attributeValue = $attribute->getValue();
            }
        }

        if ($attributeValue === null) {
            $attributeValue = '';
        }

        $result = $this->validateAttribute($attributeValue);

        return $result;
    }

    /**
     * Collect validated attributes
     *
     * This method is required for compatibility with rule conditions.
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
        return $this;
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
