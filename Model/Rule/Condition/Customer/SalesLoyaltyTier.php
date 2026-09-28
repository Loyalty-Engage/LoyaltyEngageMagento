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
use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;

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
     * @var CustomerLoyaltyDataProvider
     */
    private $customerLoyaltyDataProvider;

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
        CustomerLoyaltyDataProvider $customerLoyaltyDataProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->httpContext = $httpContext;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->customerLoyaltyDataProvider = $customerLoyaltyDataProvider;
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
        $quote = $model instanceof \Magento\Quote\Model\Quote ? $model : null;
        if ($model instanceof \Magento\Quote\Model\Quote\Address
            || $model instanceof \Magento\Quote\Model\Quote\Item\AbstractItem) {
            $quote = $model->getQuote();
        }
        if (!$quote || !$quote->getCustomerId() || $quote->getCustomerIsGuest()
            || !$this->loyaltyHelper->isLoyaltyEngageEnabled((int) $quote->getStoreId())) {
            return false;
        }
        try {
            $customer = $this->customerRepository->getById((int) $quote->getCustomerId());
            $value = $this->customerLoyaltyDataProvider->getAttributeValue(
                $customer, $this->getAttribute(), (int) $quote->getStoreId()
            );
            return $value !== null && $this->validateAttribute($value);
        } catch (\Throwable $e) {
            $this->loyaltyHelper->log('error', 'SalesLoyaltyTier', 'ValidationFailed',
                'Could not evaluate loyalty rule.', ['error' => $e->getMessage()]);
            return false;
        }
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
