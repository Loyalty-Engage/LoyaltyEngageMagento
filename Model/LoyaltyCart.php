<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

// phpcs:ignoreFile
// @codingStandardsIgnoreFile

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartItemInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyCart implements LoyaltyCartInterface
{
    public function __construct(
        private CartManagementInterface $cartManagement,
        private CartRepositoryInterface $quoteRepository,
        private LoyaltyengageCart $loyaltyengageCart,
        private ProductRepository $productRepository,
        private Response $response,
        private LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory,
        private StoreManagerInterface $storeManager,
        private QuoteFactory $quoteFactory,
        private RuleFactory $ruleFactory,
        private CouponCollectionFactory $couponCollectionFactory,
        private LoyaltyHelper $loyaltyHelper,
        private ?RuleCollectionFactory $ruleCollectionFactory = null,
        private ?CouponFactory $couponFactory = null,
        private ?GroupRepositoryInterface $customerGroupRepository = null,
        private ?SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function addProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        // Early checks
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->loyaltyHelper->successResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        if (!$customerId || !$sku) {
            return $this->loyaltyHelper->errorResponse($response, 'customerId and SKU are required.', 'validation');
        }

        $this->logDebug('Starting loyalty product addition', ['customer_id' => $customerId, 'sku' => $sku]);

        // Check minimum order value
        $minOrderValueError = $this->checkMinimumOrderValue($customerId, $response);
        if ($minOrderValueError) {
            return $minOrderValueError;
        }

        try {
            $customerData = $this->loyaltyHelper->getCustomerDataById($customerId);
            if (!$customerData) {
                return $this->loyaltyHelper->errorResponse($response, 'Customer not found.', 'validation');
            }

            $email = $customerData['email'];
            $hashedEmail = $customerData['hashed_email'];

            $this->logDebug('Calling LoyaltyEngage API', ['customer_id' => $customerId, 'email' => $email, 'sku' => $sku]);

            $apiResponse = $this->loyaltyengageCart->addToCart($hashedEmail, $sku);

            $this->logApiInteraction('addToCart', $apiResponse, ['email' => $email, 'sku' => $sku]);

            if ($apiResponse !== LoyaltyHelper::HTTP_OK) {
                return $this->loyaltyHelper->errorResponse($response, 'Product could not be added. User is not eligible.', 'api_error', $apiResponse);
            }

            $product = $this->productRepository->get($sku);
            if (!$this->isValidProduct($product)) {
                return $this->loyaltyHelper->errorResponse($response, 'Invalid or unavailable product.', 'validation');
            }

            $quote = $this->getOrCreateCustomerQuote($customerId);
            
            $quoteItem = $this->addProductToQuote($quote, $product, $sku, $email);
            
            if ($quoteItem === null) {
                return $this->loyaltyHelper->errorResponse($response, 'Failed to add product to cart.', 'system_error');
            }

            $this->logProductAddition($sku, $email, $quote, $quoteItem, $apiResponse);

            return $this->loyaltyHelper->successResponse($response, 'Product added successfully');
            
        } catch (\Throwable $e) {
            return $this->handleException($e, 'addProduct', ['sku' => $sku, 'customer_id' => $customerId]);
        }
    }

    public function addMultipleProducts(int $customerId, array $skus): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->loyaltyHelper->successResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        if (!$customerId || empty($skus)) {
            return $this->loyaltyHelper->errorResponse($response, 'customerId and SKUs are required.', 'validation');
        }

        // Check minimum order value
        $minOrderValueError = $this->checkMinimumOrderValue($customerId, $response);
        if ($minOrderValueError) {
            return $minOrderValueError;
        }

        try {
            $customerData = $this->loyaltyHelper->getCustomerDataById($customerId);
            if (!$customerData) {
                return $this->loyaltyHelper->errorResponse($response, 'Customer not found.', 'validation');
            }

            $email = $customerData['email'];
            $hashedEmail = $customerData['hashed_email'];
            $quote = $this->getOrCreateCustomerQuote($customerId);
            
            $result = $this->processMultipleProducts($quote, $hashedEmail, $email, $skus);
            
            if ($result['success_count'] === 0) {
                return $this->loyaltyHelper->errorResponse($response, 'Failed to add any products to the cart.', 'api_error');
            }

            $message = $this->buildSuccessMessage($result['success_count'], $result['failed_skus']);
            return $this->loyaltyHelper->successResponse($response, $message);
            
        } catch (\Throwable $e) {
            return $this->handleException($e, 'addMultipleProducts', ['customer_id' => $customerId, 'skus' => $skus]);
        }
    }

    public function buyDiscountCodeProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->loyaltyHelper->successResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customerData = $this->loyaltyHelper->getCustomerDataById($customerId);
            if (!$customerData) {
                return $this->loyaltyHelper->errorResponse($response, 'Customer not found.', 'validation');
            }

            $email = $customerData['email'];
            $hashedEmail = $customerData['hashed_email'];

            $discountResult = $this->loyaltyengageCart->buyDiscountCode($hashedEmail, $sku);

            if (!$discountResult || empty($discountResult['discountCode'])) {
                return $this->loyaltyHelper->errorResponse($response, 'Failed to purchase discount code. Please check your available coins.', 'api_error');
            }

            $discountCode = $discountResult['discountCode'];
            
            if (strlen($discountCode) > 255) {
                return $this->loyaltyHelper->errorResponse($response, 'Discount code is too long for Magento.', 'validation');
            }

            $usePercentage = isset($discountResult['discountPercentage']) && $discountResult['discountPercentage'] > 0;
            $discountValue = $usePercentage ? $discountResult['discountPercentage'] : ($discountResult['discountAmount'] ?? 0);

            $this->logDiscountPurchase($email, $sku, $discountResult);

            $finalCode = $this->ensureCartRuleExists($discountCode, $discountValue, !$usePercentage);

            $quote = $this->getOrCreateCustomerQuote($customerId);
            $quote->setCouponCode($finalCode);
            $quote->collectTotals()->save();

            $discountTypeText = $usePercentage ? "{$discountResult['discountPercentage']}%" : "€{$discountResult['discountAmount']}";
            return $this->loyaltyHelper->successResponse(
                $response,
                "Discount code '{$finalCode}' ({$discountTypeText}) applied successfully. You spent {$discountResult['spentCoins']} coins."
            );
            
        } catch (\Exception $e) {
            return $this->handleException($e, 'buyDiscountCodeProduct', ['customer_id' => $customerId, 'sku' => $sku]);
        }
    }

    public function claimDiscountAfterAddToLoyaltyCart(int $customerId, string $orderId, array $products): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->loyaltyHelper->successResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        if (!$customerId || !$orderId || empty($products)) {
            return $this->loyaltyHelper->errorResponse($response, 'customerId, orderId, and products are required.', 'validation');
        }

        try {
            $formattedProducts = $this->validateAndFormatProducts($products, $response);
            if ($formattedProducts === null) {
                return $response;
            }

            $customerData = $this->loyaltyHelper->getCustomerDataById($customerId);
            if (!$customerData) {
                return $this->loyaltyHelper->errorResponse($response, 'Customer not found.', 'validation');
            }

            $email = $customerData['email'];

            $this->logDiscountClaim($customerId, $email, $orderId, $formattedProducts);

            $placeOrderResult = $this->loyaltyengageCart->placeOrder($email, $orderId, $formattedProducts);

            if ($placeOrderResult === LoyaltyHelper::HTTP_OK) {
                return $this->loyaltyHelper->successResponse($response, 'Discount claimed successfully. Order placed.');
            }

            return $this->loyaltyHelper->errorResponse($response, 'Failed to claim discount. Please try again.', 'api_error', $placeOrderResult);
            
        } catch (\Exception $e) {
            return $this->handleException($e, 'claimDiscountAfterAddToLoyaltyCart', [
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'products' => $products
            ]);
        }
    }

    public function ensureCartRuleExists(?string $code, float $discountRate, bool $forceCartFixed = false): string
    {
        try {
            if (empty($code)) {
                $code = 'LOYALTY-' . strtoupper(bin2hex(random_bytes(4)));
            }

            // Check if this coupon code already exists
            $couponCollection = $this->couponCollectionFactory->create()
                ->addFieldToFilter('code', $code);

            if ($couponCollection->getSize() > 0) {
                return $code;
            }

            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            $customerGroupIds = $this->getAllCustomerGroupIds();
            $simpleAction = $forceCartFixed ? 'cart_fixed' : 'by_percent';

            // Generate a consistent rule name based on discount type and value
            $ruleName = $this->generateLoyaltyRuleName($discountRate, $forceCartFixed);

            // Try to find an existing rule with the same discount value and type
            $existingRule = null;

            // Log dependency status for debugging
            $this->loyaltyHelper->log(
                'info',
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                'Checking dependencies for rule reuse',
                [
                    'ruleCollectionFactory_available' => $this->ruleCollectionFactory !== null,
                    'couponFactory_available' => $this->couponFactory !== null,
                    'discount_rate' => $discountRate,
                    'simple_action' => $simpleAction,
                    'rule_name' => $ruleName
                ]
            );

            if ($this->ruleCollectionFactory !== null && $this->couponFactory !== null) {
                $existingRule = $this->findExistingLoyaltyRule($discountRate, $simpleAction, $websiteId);
            } else {
                $this->loyaltyHelper->log(
                    'error',
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    'Dependencies not available - falling back to creating new rule. Run setup:di:compile',
                    [
                        'ruleCollectionFactory' => $this->ruleCollectionFactory !== null ? 'available' : 'NULL',
                        'couponFactory' => $this->couponFactory !== null ? 'available' : 'NULL'
                    ]
                );
            }

            if ($existingRule && $this->couponFactory !== null) {
                // Add the new coupon code to the existing rule
                $this->addCouponToRule($existingRule, $code);

                $this->loyaltyHelper->log(
                    'info',
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_SUCCESS,
                    sprintf('Added coupon %s to existing rule: %s', $code, $existingRule->getName()),
                    [
                        'coupon_code' => $code,
                        'rule_id' => $existingRule->getId(),
                        'rule_name' => $existingRule->getName(),
                        'discount_rate' => $discountRate,
                        'discount_type' => $simpleAction
                    ]
                );

                return $code;
            }

            // No existing rule found OR factories not available - create a new rule
            $rule = $this->ruleFactory->create();
            $rule->setName($ruleName)
                ->setDescription('Auto-generated from LoyaltyEngage')
                ->setFromDate(date('Y-m-d'))
                ->setIsActive(1)
                ->setSimpleAction($simpleAction)
                ->setDiscountAmount($discountRate)
                ->setStopRulesProcessing(1)
                ->setIsAdvanced(1)
                ->setUsesPerCustomer(0)
                ->setCustomerGroupIds($customerGroupIds)
                ->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC)
                ->setUseAutoGeneration(1)
                ->setUsesPerCoupon(1)
                ->setWebsiteIds([$websiteId]);

            $rule->save();

            // Add the first coupon as a managed coupon so it appears in Manage Coupon Codes
            $this->addCouponToRule($rule, $code);

            $this->loyaltyHelper->log(
                'info',
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Created new rule: %s with coupon: %s', $ruleName, $code),
                [
                    'coupon_code' => $code,
                    'rule_id' => $rule->getId(),
                    'rule_name' => $ruleName,
                    'discount_rate' => $discountRate,
                    'discount_type' => $simpleAction
                ]
            );

            return $code;

        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->critical('[LoyaltyShop] Unexpected error in ensureCartRuleExists', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Add a coupon code to an existing cart rule via CouponFactory
     *
     * @param \Magento\SalesRule\Model\Rule $rule
     * @param string $code
     * @return void
     */
    private function addCouponToRule($rule, string $code): void
    {
        // If CouponFactory is not available, throw an exception
        if ($this->couponFactory === null) {
            throw new \RuntimeException('CouponFactory is not available. Please run setup:di:compile.');
        }

        $coupon = $this->couponFactory->create();
        $coupon->setRuleId($rule->getId())
            ->setCode($code)
            ->setUsageLimit(1) // Each coupon can only be used once
            ->setUsagePerCustomer(1)
            ->setIsPrimary(false) // Keep coupon_code field on rule empty
            ->setType(\Magento\SalesRule\Model\Coupon::TYPE_GENERATED);

        $coupon->save();
    }

    /**
     * Find an existing LoyaltyEngage rule with the same discount value and type
     *
     * @param float $discountRate
     * @param string $simpleAction
     * @param int $websiteId
     * @return \Magento\SalesRule\Model\Rule|null
     */
    private function findExistingLoyaltyRule(float $discountRate, string $simpleAction, int $websiteId)
    {
        if ($this->ruleCollectionFactory === null) {
            $this->loyaltyHelper->log(
                'debug',
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                'RuleCollectionFactory is null, cannot search for existing rules'
            );
            return null;
        }

        $ruleName = $this->generateLoyaltyRuleName($discountRate, $simpleAction === 'cart_fixed');

        $this->loyaltyHelper->log(
            'debug',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            sprintf('Searching for existing rule: %s', $ruleName),
            ['rule_name' => $ruleName, 'simple_action' => $simpleAction, 'discount_rate' => $discountRate]
        );

        $ruleCollection = $this->ruleCollectionFactory->create()
            ->addFieldToFilter('name', $ruleName)
            ->addFieldToFilter('simple_action', $simpleAction)
            ->addFieldToFilter('discount_amount', $discountRate)
            ->addFieldToFilter('is_active', 1);

        if ($ruleCollection->getSize() > 0) {
            $rule = $ruleCollection->getFirstItem();

            $this->loyaltyHelper->log(
                'debug',
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Found existing rule: %s (ID: %d)', $rule->getName(), $rule->getId()),
                ['rule_id' => $rule->getId(), 'rule_name' => $rule->getName()]
            );

            return $rule;
        }

        $this->loyaltyHelper->log(
            'debug',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            sprintf('No existing rule found for: %s', $ruleName)
        );

        return null;
    }

    /**
     * Generate a consistent rule name based on discount type and value
     *
     * @param float $discountRate
     * @param bool $isFixed
     * @return string
     */
    private function generateLoyaltyRuleName(float $discountRate, bool $isFixed): string
    {
        if ($isFixed) {
            return sprintf('LoyaltyEngage Fixed €%.2f', $discountRate);
        }
        return sprintf('LoyaltyEngage Percentage %.1f%%', $discountRate);
    }

    /**
     * Get all customer group IDs with fallback to default groups
     *
     * @return array
     */
    private function getAllCustomerGroupIds(): array
    {
        if ($this->customerGroupRepository === null || $this->searchCriteriaBuilderFactory === null) {
            // Fallback: NOT LOGGED IN (0), General (1), Wholesale (2), Retailer (3)
            return [0, 1, 2, 3];
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()->create();
            $groups = $this->customerGroupRepository->getList($searchCriteria);
            $groupIds = [];
            foreach ($groups->getItems() as $group) {
                $groupIds[] = $group->getId();
            }
            return !empty($groupIds) ? $groupIds : [0, 1, 2, 3];
        } catch (\Exception $e) {
            return [0, 1, 2, 3];
        }
    }

    public function isValidProduct($product): bool
    {
        return $product->isSalable() && $product->getStatus() == 1;
    }

    public function getOrCreateCustomerQuote(int $customerId)
    {
        try {
            return $this->quoteRepository->getActiveForCustomer($customerId);

        } catch (NoSuchEntityException $e) {
            try {
                $store = $this->storeManager->getStore();

                $quote = $this->quoteFactory->create();
                $quote->setStoreId($store->getId());
                $quote->setCustomerId($customerId);
                $quote->setCustomerIsGuest(false);
                $quote->setIsActive(1);

                $quote->save();

                $this->loyaltyHelper->log(
                    'info',
                    'QUOTE',
                    'NEW_QUOTE_CREATED',
                    'New active quote created',
                    [
                        'customer_id' => $customerId,
                        'quote_id' => $quote->getId()
                    ]
                );

                return $quote;

            } catch (\Throwable $e) {

                $this->loyaltyHelper->log(
                    'critical',
                    'QUOTE',
                    'CREATE_CART_FAILED',
                    'Failed to create new quote',
                    [
                        'customer_id' => $customerId,
                        'error' => $e->getMessage()
                    ]
                );

                throw $e;
            }
        }
    }

    private function getCartSubtotalExcludingLoyaltyProducts(int $customerId): float
    {
        try {
            $quote = $this->quoteRepository->getActiveForCustomer($customerId);
            $subtotal = 0.0;

            foreach ($quote->getAllVisibleItems() as $item) {
                $customPrice = $item->getCustomPrice();
                $loyaltyLockedQty = $item->getOptionByCode('loyalty_locked_qty');
                
                $isLoyaltyProduct = ($customPrice !== null && (float)$customPrice === 0.0) || 
                                    ($loyaltyLockedQty && $loyaltyLockedQty->getValue());
                
                if (!$isLoyaltyProduct) {
                    $subtotal += (float)$item->getRowTotal();
                }
            }

            return $subtotal;
            
        } catch (NoSuchEntityException $e) {
            return 0.0;
        }
    }

    private function checkMinimumOrderValue(int $customerId, LoyaltyCartResponseInterface $response): ?LoyaltyCartResponseInterface
    {
        if (!($this->loyaltyHelper->getMinimumOrderValueForLoyalty() > 0)) {
            return null;
        }

        $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty();
        $cartSubtotal = $this->getCartSubtotalExcludingLoyaltyProducts($customerId);
        
        if ($cartSubtotal >= $minimumOrderValue) {
            return null;
        }

        $this->loyaltyHelper->log(
            'info',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_VALIDATION,
            sprintf('Minimum order value not met - Required: %.2f, Current: %.2f', $minimumOrderValue, $cartSubtotal),
            ['customer_id' => $customerId, 'minimum' => $minimumOrderValue, 'current' => $cartSubtotal]
        );

        $errorMessage = $this->loyaltyHelper->getFormattedMinimumOrderValueMessage($minimumOrderValue, $cartSubtotal);
        
        return $this->setMinimumOrderValueErrorResponse($response, $errorMessage);
    }

    private function addProductToQuote($quote, $product, string $sku, string $email)
    {
        // Check if product already exists
        $existingItem = null;
        foreach ($quote->getAllItems() as $item) {
            if ($item->getProduct()->getSku() === $sku) {
                $existingItem = $item;
                break;
            }
        }

        if ($existingItem) {
            $this->logDebug('Product already in cart', ['sku' => $sku, 'email' => $email]);
            $quoteItem = $existingItem;
        } else {
            $quoteItem = $quote->addProduct($product);
            $this->logDebug('Product added to cart', ['sku' => $sku, 'email' => $email]);
        }

        $quoteItem->setCustomPrice(0);
        $quoteItem->setOriginalCustomPrice(0);
        $quoteItem->setData('loyalty_locked_qty', 1);
        $quoteItem->addOption(['code' => 'loyalty_locked_qty', 'value' => 1]);
        
        $quote->collectTotals()->save();
        
        return $quoteItem;
    }

    private function processMultipleProducts($quote, string $hashedEmail, string $email, array $skus): array
    {
        $successCount = 0;
        $failedSkus = [];

        foreach ($skus as $sku) {
            $apiResponse = $this->loyaltyengageCart->addToCart($hashedEmail, $sku);
            
            if ($apiResponse !== LoyaltyHelper::HTTP_OK) {
                $failedSkus[] = $sku;
                continue;
            }

            try {
                $product = $this->productRepository->get($sku);
                if (!$this->isValidProduct($product)) {
                    $failedSkus[] = $sku;
                    continue;
                }

                // Check if product already exists
                $productExists = false;
                foreach ($quote->getAllItems() as $item) {
                    if ($item->getProduct()->getSku() === $sku) {
                        $productExists = true;
                        break;
                    }
                }

                if (!$productExists) {
                    $quoteItem = $quote->addProduct($product);
                    $quoteItem->setCustomPrice(0);
                    $quoteItem->setOriginalCustomPrice(0);
                    $quoteItem->setData('loyalty_locked_qty', 1);
                    $quoteItem->addOption(['code' => 'loyalty_locked_qty', 'value' => 1]);
                }
                
                $successCount++;
                
            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'critical',
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    '[LoyaltyShop] Exception in addMultipleProducts for SKU: ' . $sku,
                    [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
                $failedSkus[] = $sku;
            }
        }

        $quote->collectTotals()->save();
        
        return [
            'success_count' => $successCount,
            'failed_skus' => $failedSkus
        ];
    }

    private function validateAndFormatProducts(array $products, LoyaltyCartResponseInterface $response): ?array
    {
        $formattedProducts = [];
        
        foreach ($products as $product) {
            if (!$product instanceof LoyaltyCartItemInterface) {
                $this->loyaltyHelper->errorResponse($response, 'Each product must be a valid LoyaltyCartItem object.', 'validation');
                return null;
            }
            
            $sku = $product->getSku();
            $quantity = $product->getQuantity();
            
            if (empty($sku) || $quantity <= 0) {
                $this->loyaltyHelper->errorResponse($response, 'Each product must have valid SKU and quantity.', 'validation');
                return null;
            }
            
            $formattedProducts[] = [
                'sku' => (string) $sku,
                'quantity' => (int) $quantity
            ];
        }
        
        return $formattedProducts;
    }

    private function setMinimumOrderValueErrorResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        $this->response->setHttpResponseCode(LoyaltyHelper::HTTP_BAD_REQUEST);
        
        $barColor = $this->loyaltyHelper->getMinimumOrderValueBarColor();
        $textColor = $this->loyaltyHelper->getMinimumOrderValueTextColor();
        
        return $response
            ->setSuccess(false)
            ->setMessage($message)
            ->setBarColor($barColor)
            ->setTextColor($textColor)
            ->setErrorType('minimum_order_value');
    }

    private function handleException(\Throwable $e, string $method, array $context): LoyaltyCartResponseInterface
    {
        $this->loyaltyHelper->log(
            'critical',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_ERROR,
            sprintf('Exception in %s: %s', $method, $e->getMessage()),
            array_merge($context, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ])
        );
        
        return $this->loyaltyHelper->errorResponse(
            $this->loyaltyCartResponseFactory->create(),
            'An unexpected error occurred.',
            'system_error'
        );
    }

    private function buildSuccessMessage(int $successCount, array $failedSkus): string
    {
        $message = 'Successfully added ' . $successCount . ' product(s) to the cart.';
        if (!empty($failedSkus)) {
            $message .= ' Failed to add: ' . implode(', ', $failedSkus);
        }
        return $message;
    }

    private function logDebug(string $message, array $context = []): void
    {
        $this->loyaltyHelper->log(
            'debug',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            $message,
            $context
        );
    }

    private function logApiInteraction(string $endpoint, $response, array $context = []): void
    {
        $this->loyaltyHelper->log(
            'debug',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            $endpoint,
            [
                'method' => 'POST',
                'response' => $response,
                'status' => $response === LoyaltyHelper::HTTP_OK ? 'Success' : 'User not eligible',
                'context' => $context
            ]
        );
    }

    private function logProductAddition(string $sku, string $email, $quote, $quoteItem, $apiResponse): void
    {
        $this->loyaltyHelper->log(
            'info',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            sprintf('Successfully processed loyalty product %s for customer %s', $sku, $email),
            [
                'product_id' => $quoteItem->getProductId(),
                'product_name' => $quoteItem->getProduct()->getName(),
                'quote_id' => $quote->getId(),
                'quote_item_id' => $quoteItem->getId(),
                'api_response' => $apiResponse
            ]
        );
    }

    private function logDiscountPurchase(string $email, string $sku, array $discountResult): void
    {
        $this->loyaltyHelper->log(
            'info',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_SUCCESS,
            sprintf('Discount code purchased: %s', $discountResult['discountCode']),
            [
                'email' => $email,
                'sku' => $sku,
                'discount_code' => $discountResult['discountCode'],
                'discount_percentage' => $discountResult['discountPercentage'] ?? null,
                'discount_amount' => $discountResult['discountAmount'] ?? null,
                'spent_coins' => $discountResult['spentCoins'] ?? 0,
                'available_coins' => $discountResult['availableCoins'] ?? 0
            ]
        );
    }

    private function logDiscountClaim(int $customerId, string $email, string $orderId, array $products): void
    {
        $this->loyaltyHelper->log(
            'info',
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            'Claiming discount after adding products to loyalty cart',
            [
                'customer_id' => $customerId,
                'email' => $this->loyaltyHelper->logMaskedEmail($email),
                'order_id' => $orderId,
                'products' => $products
            ]
        );
    }
}
