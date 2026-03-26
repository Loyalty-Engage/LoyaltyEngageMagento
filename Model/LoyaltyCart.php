<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyCart implements LoyaltyCartInterface
{
    protected const HTTP_OK = 200;
    protected const HTTP_BAD_REQUEST = 401;

    public function __construct(
        protected CartItemInterfaceFactory $cartItemFactory,
        protected CartManagementInterface $cartManagement,
        protected CartRepositoryInterface $quoteRepository,
        protected CustomerRepositoryInterface $customerRepository,
        protected Curl $curl,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected ProductRepository $productRepository,
        protected RestRequest $request,
        protected Response $response,
        protected ScopeConfigInterface $scopeConfig,
        protected LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory,
        protected StoreManagerInterface $storeManager,
        protected RuleFactory $ruleFactory,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected Session $customerSession,
        protected LoggerInterface $logger,
        protected CouponCollectionFactory $couponCollectionFactory,
        protected LoyaltyLogger $loyaltyLogger,
        protected LoyaltyHelper $loyaltyHelper,
        protected ?RuleCollectionFactory $ruleCollectionFactory = null,
        protected ?CouponFactory $couponFactory = null,
        protected ?GroupRepositoryInterface $customerGroupRepository = null,
        protected ?SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory = null
    ) {
    }

    public function addProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            // Return a successful response, as no error occurred, but no action was taken.
            return $this->setSuccessResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        // Log the start of loyalty product addition (debug only)
        $this->loyaltyLogger->debug(
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            sprintf('Starting loyalty product addition - Customer ID: %d, SKU: %s', $customerId, $sku),
            ['customer_id' => $customerId, 'sku' => $sku]
        );

        // Check minimum order value requirement
        if ($this->loyaltyHelper->isMinimumOrderValueEnabled()) {
            $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty();
            $cartSubtotal = $this->getCartSubtotalExcludingLoyaltyProducts($customerId);
            
            if ($cartSubtotal < $minimumOrderValue) {
                $this->loyaltyLogger->info(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_VALIDATION,
                    sprintf('Minimum order value not met - Required: %.2f, Current: %.2f', $minimumOrderValue, $cartSubtotal),
                    ['customer_id' => $customerId, 'minimum' => $minimumOrderValue, 'current' => $cartSubtotal]
                );
                
                // Use configurable message from admin
                $errorMessage = $this->loyaltyHelper->getFormattedMinimumOrderValueMessage($minimumOrderValue, $cartSubtotal);
                
                return $this->setMinimumOrderValueErrorResponse($response, $errorMessage);
            }
        }

        if (!$customerId || !$sku) {
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_VALIDATION,
                'Missing required parameters for loyalty product addition',
                ['customer_id' => $customerId, 'sku' => $sku]
            );
            return $this->setErrorResponse($response, 'customerId and SKU are required.');
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $email = $customer->getEmail();

            // Log API call to LoyaltyEngage (debug only)
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf('Calling LoyaltyEngage API for %s with SKU %s', $email, $sku),
                ['email' => $email, 'sku' => $sku]
            );

            $apiResponse = $this->loyaltyengageCart->addToCart($email, $sku);

            // Log API response
            $this->loyaltyLogger->logApiInteraction(
                'addToCart',
                'POST',
                $apiResponse,
                $apiResponse === self::HTTP_OK ? 'Success' : 'User not eligible',
                ['email' => $email, 'sku' => $sku]
            );

            if ($apiResponse !== self::HTTP_OK) {
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    sprintf('LoyaltyEngage API rejected product %s for %s (Response: %d)', $sku, $email, $apiResponse),
                    ['email' => $email, 'sku' => $sku, 'api_response' => $apiResponse]
                );
                return $this->setErrorResponse($response, 'Product could not be added. User is not eligible.');
            }

            $product = $this->productRepository->get($sku);
            if (!$this->isValidProduct($product)) {
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_VALIDATION,
                    sprintf('Product %s is not valid or salable', $sku),
                    ['sku' => $sku, 'product_id' => $product->getId(), 'status' => $product->getStatus()]
                );
                return $this->setErrorResponse($response, 'Invalid or unavailable product.');
            }

            $quote = $this->getOrCreateCustomerQuote($customerId);

            // Check if the product already exists in the cart
            $productAlreadyInCart = false;
            foreach ($quote->getAllItems() as $item) {
                if ($item->getProduct()->getSku() === $sku) {
                    $productAlreadyInCart = true;
                    $this->loyaltyLogger->debug(
                        LoyaltyLogger::COMPONENT_API,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf('Product %s already exists in cart for customer %s', $sku, $email),
                        ['sku' => $sku, 'email' => $email, 'quote_id' => $quote->getId()]
                    );
                    break;
                }
            }

            // Only add the product if it's not already in the cart
            if (!$productAlreadyInCart) {
                $quoteItem = $quote->addProduct($product);
                $quoteItem->setCustomPrice(0);
                $quoteItem->setOriginalCustomPrice(0);
                $quoteItem->setData('loyalty_locked_qty', 1);
                $quoteItem->addOption(['code' => 'loyalty_locked_qty', 'value' => 1]);

                // Log the loyalty flags being set (debug only)
                $this->loyaltyLogger->debug(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_LOYALTY,
                    sprintf('Loyalty flags set for product %s - Custom price: 0, loyalty_locked_qty: 1', $sku),
                    [
                        'sku' => $sku,
                        'quote_item_id' => $quoteItem->getId(),
                        'custom_price' => $quoteItem->getCustomPrice(),
                        'loyalty_locked_qty_data' => $quoteItem->getData('loyalty_locked_qty'),
                        'loyalty_locked_qty_option' => $quoteItem->getOptionByCode('loyalty_locked_qty') ?
                            $quoteItem->getOptionByCode('loyalty_locked_qty')->getValue() : 'not_set'
                    ]
                );

                // Log cart addition
                $this->loyaltyLogger->logCartAddition(
                    LoyaltyLogger::ACTION_LOYALTY,
                    $sku,
                    $email,
                    'loyalty-api',
                    [
                        'product_id' => $product->getId(),
                        'product_name' => $product->getName(),
                        'quote_id' => $quote->getId(),
                        'quote_item_id' => $quoteItem->getId(),
                        'api_response' => $apiResponse
                    ]
                );
            }

            $quote->collectTotals()->save();

            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Successfully processed loyalty product %s for customer %s', $sku, $email),
                ['sku' => $sku, 'email' => $email, 'quote_id' => $quote->getId()]
            );

            return $this->setSuccessResponse($response, 'Product toegevoegd aan winkelmand');
        } catch (\Throwable $e) {
            $this->loyaltyLogger->critical(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('Exception in addProduct for SKU %s: %s', $sku, $e->getMessage()),
                [
                    'sku' => $sku,
                    'customer_id' => $customerId,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            return $this->setErrorResponse($response, 'An error occurred while adding the product.');
        }
    }

    private function isValidProduct($product): bool
    {
        return $product->isSalable() && $product->getStatus() == 1;
    }

    private function getOrCreateCustomerQuote(int $customerId)
    {
        try {
            return $this->quoteRepository->getActiveForCustomer($customerId);
        } catch (NoSuchEntityException) {
            $quoteId = $this->cartManagement->createEmptyCart();
            $quote = $this->quoteRepository->get($quoteId);
            $customer = $this->customerRepository->getById($customerId);
            $quote->assignCustomer($customer);
            $quote->setCustomerIsGuest(false);
            return $quote;
        }
    }

    /**
     * Calculate cart subtotal excluding loyalty products (products with custom price of 0)
     *
     * @param int $customerId
     * @return float
     */
    private function getCartSubtotalExcludingLoyaltyProducts(int $customerId): float
    {
        try {
            $quote = $this->quoteRepository->getActiveForCustomer($customerId);
            $subtotal = 0.0;

            foreach ($quote->getAllVisibleItems() as $item) {
                // Skip loyalty products (items with custom price of 0 or loyalty_locked_qty option)
                $customPrice = $item->getCustomPrice();
                $loyaltyLockedQty = $item->getOptionByCode('loyalty_locked_qty');
                
                if ($customPrice !== null && (float)$customPrice === 0.0) {
                    // This is a loyalty product, skip it
                    continue;
                }
                
                if ($loyaltyLockedQty && $loyaltyLockedQty->getValue()) {
                    // This is a loyalty product, skip it
                    continue;
                }

                // Add the row total of non-loyalty products
                $subtotal += (float)$item->getRowTotal();
            }

            return $subtotal;
        } catch (NoSuchEntityException $e) {
            // No active cart exists, return 0
            return 0.0;
        }
    }

    private function setSuccessResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        return $response->setSuccess(true)->setMessage($message);
    }

    private function setErrorResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        $this->response->setHttpResponseCode(self::HTTP_BAD_REQUEST);
        return $response->setSuccess(false)->setMessage($message);
    }

    /**
     * Set error response for minimum order value with configurable styling
     *
     * @param LoyaltyCartResponseInterface $response
     * @param string $message
     * @return LoyaltyCartResponseInterface
     */
    private function setMinimumOrderValueErrorResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        $this->response->setHttpResponseCode(self::HTTP_BAD_REQUEST);
        
        // Add styling information to the response for frontend rendering
        $barColor = $this->loyaltyHelper->getMinimumOrderValueBarColor();
        $textColor = $this->loyaltyHelper->getMinimumOrderValueTextColor();
        
        return $response
            ->setSuccess(false)
            ->setMessage($message)
            ->setBarColor($barColor)
            ->setTextColor($textColor)
            ->setErrorType('minimum_order_value');
    }

    /**
     * Ensure a cart rule exists for the given discount code.
     * If a rule with the same discount value and type already exists, add the coupon to that rule.
     * Otherwise, create a new rule.
     *
     * @param string|null $code The coupon code
     * @param float $discountRate The discount amount (percentage or fixed)
     * @param bool $forceCartFixed If true, use fixed amount discount; otherwise use percentage
     * @return string The coupon code
     */
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
            $this->loyaltyLogger->info(
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
                $this->loyaltyLogger->error(
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
                
                $this->loyaltyLogger->info(
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

            // No existing rule found OR factories not available - create a new rule with the coupon code directly
            $rule = $this->ruleFactory->create();
            $rule->setName($ruleName)
                ->setDescription('Auto-generated from LoyaltyEngage')
                ->setFromDate(date('Y-m-d'))
                ->setIsActive(1)
                ->setSimpleAction($simpleAction)
                ->setDiscountAmount($discountRate)
                ->setStopRulesProcessing(0)
                ->setIsAdvanced(1)
                ->setUsesPerCustomer(1)
                ->setCustomerGroupIds($customerGroupIds)
                ->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC)
                ->setCouponCode($code) // Set coupon code directly on the rule
                ->setUsesPerCoupon(1)
                ->setWebsiteIds([$websiteId]);

            $rule->save();

            $this->loyaltyLogger->info(
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
            $this->logger->critical('[LoyaltyShop] Unexpected error in ensureCartRuleExists', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
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
     * Find an existing LoyaltyEngage rule with the same discount value and type
     *
     * @param float $discountRate
     * @param string $simpleAction
     * @param int $websiteId
     * @return \Magento\SalesRule\Model\Rule|null
     */
    private function findExistingLoyaltyRule(float $discountRate, string $simpleAction, int $websiteId)
    {
        // If RuleCollectionFactory is not available, return null (fallback to old behavior)
        if ($this->ruleCollectionFactory === null) {
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                'RuleCollectionFactory is null, cannot search for existing rules'
            );
            return null;
        }
        
        $ruleName = $this->generateLoyaltyRuleName($discountRate, $simpleAction === 'cart_fixed');
        
        $this->loyaltyLogger->debug(
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
            
            // Ensure the rule supports multiple coupons (coupon_type = 3 = auto-generation)
            // If it's still type 2 (specific), we need to update it
            if ((int)$rule->getCouponType() !== \Magento\SalesRule\Model\Rule::COUPON_TYPE_AUTO) {
                $this->loyaltyLogger->debug(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_LOYALTY,
                    sprintf('Updating rule %s coupon_type from %d to AUTO (3)', $rule->getName(), $rule->getCouponType()),
                    ['rule_id' => $rule->getId(), 'old_coupon_type' => $rule->getCouponType()]
                );
                
                $rule->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_AUTO);
                $rule->setUseAutoGeneration(1);
                $rule->save();
            }
            
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Found existing rule: %s (ID: %d)', $rule->getName(), $rule->getId()),
                ['rule_id' => $rule->getId(), 'rule_name' => $rule->getName()]
            );
            
            return $rule;
        }
        
        $this->loyaltyLogger->debug(
            LoyaltyLogger::COMPONENT_API,
            LoyaltyLogger::ACTION_LOYALTY,
            sprintf('No existing rule found for: %s', $ruleName)
        );
        
        return null;
    }

    /**
     * Add a coupon code to an existing rule
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
            ->setIsPrimary(false)
            ->setType(\Magento\SalesRule\Model\Coupon::TYPE_GENERATED);
        
        $coupon->save();
    }

    /**
     * Get all customer group IDs from the database
     * This ensures we only use customer groups that actually exist
     *
     * @return array
     */
    private function getAllCustomerGroupIds(): array
    {
        // If the repository is not available, fall back to common defaults
        if ($this->customerGroupRepository === null || $this->searchCriteriaBuilderFactory === null) {
            // Return only group 0 (NOT LOGGED IN) and 1 (General) as safe defaults
            return [0, 1];
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()->create();
            $customerGroups = $this->customerGroupRepository->getList($searchCriteria);
            
            $groupIds = [];
            foreach ($customerGroups->getItems() as $group) {
                $groupIds[] = (int) $group->getId();
            }
            
            // Ensure we have at least some groups
            if (empty($groupIds)) {
                return [0, 1];
            }
            
            return $groupIds;
        } catch (\Exception $e) {
            $this->logger->warning('[LoyaltyShop] Could not fetch customer groups, using defaults', [
                'message' => $e->getMessage()
            ]);
            // Fall back to safe defaults
            return [0, 1];
        }
    }

    /**
     * Add multiple products to the cart using loyalty points.
     *
     * @param int $customerId
     * @param string[] $skus
     * @return LoyaltyCartResponseInterface
     */
    public function addMultipleProducts(int $customerId, array $skus): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            // Return a successful response, as no error occurred, but no action was taken.
            return $this->setSuccessResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        if (!$customerId || empty($skus)) {
            return $this->setErrorResponse($response, 'customerId and SKUs are required.');
        }

        // Check minimum order value requirement
        if ($this->loyaltyHelper->isMinimumOrderValueEnabled()) {
            $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty();
            $cartSubtotal = $this->getCartSubtotalExcludingLoyaltyProducts($customerId);
            
            if ($cartSubtotal < $minimumOrderValue) {
                $this->loyaltyLogger->info(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_VALIDATION,
                    sprintf('Minimum order value not met for multiple products - Required: %.2f, Current: %.2f', $minimumOrderValue, $cartSubtotal),
                    ['customer_id' => $customerId, 'minimum' => $minimumOrderValue, 'current' => $cartSubtotal, 'skus' => $skus]
                );
                
                // Use configurable message from admin
                $errorMessage = $this->loyaltyHelper->getFormattedMinimumOrderValueMessage($minimumOrderValue, $cartSubtotal);
                
                return $this->setMinimumOrderValueErrorResponse($response, $errorMessage);
            }
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $email = $customer->getEmail();
            $quote = $this->getOrCreateCustomerQuote($customerId);
            $successCount = 0;
            $failedSkus = [];

            foreach ($skus as $sku) {
                $apiResponse = $this->loyaltyengageCart->addToCart($email, $sku);

                if ($apiResponse !== self::HTTP_OK) {
                    $failedSkus[] = $sku;
                    continue;
                }

                try {
                    $product = $this->productRepository->get($sku);
                    if (!$this->isValidProduct($product)) {
                        $failedSkus[] = $sku;
                        continue;
                    }

                    // Check if the product already exists in the cart
                    $productAlreadyInCart = false;
                    foreach ($quote->getAllItems() as $item) {
                        if ($item->getProduct()->getSku() === $sku) {
                            $productAlreadyInCart = true;
                            break;
                        }
                    }

                    // Only add the product if it's not already in the cart
                    if (!$productAlreadyInCart) {
                        $quoteItem = $quote->addProduct($product);
                        $quoteItem->setCustomPrice(0);
                        $quoteItem->setOriginalCustomPrice(0);
                        $quoteItem->setData('loyalty_locked_qty', 1);
                        $quoteItem->addOption(['code' => 'loyalty_locked_qty', 'value' => 1]);
                        $successCount++;
                    } else {
                        $successCount++; // Count as success if already in cart
                    }
                } catch (\Exception $e) {
                    $this->logger->critical('[LoyaltyShop] Exception in addMultipleProducts for SKU: ' . $sku, [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $failedSkus[] = $sku;
                }
            }

            $quote->collectTotals()->save();

            if ($successCount === 0) {
                return $this->setErrorResponse($response, 'Failed to add any products to the cart.');
            }

            $message = 'Successfully added ' . $successCount . ' product(s) to the cart.';
            if (!empty($failedSkus)) {
                $message .= ' Failed to add: ' . implode(', ', $failedSkus);
            }

            return $this->setSuccessResponse($response, $message);
        } catch (\Throwable $e) {
            $this->logger->critical('[LoyaltyShop] Exception in addMultipleProducts', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->setErrorResponse($response, 'An error occurred while adding the products.');
        }
    }

    /**
     * Buy a discount code product using loyalty coins and apply the discount to the cart
     *
     * @param int $customerId
     * @param string $sku SKU of the discount code product in LoyaltyEngage
     * @return LoyaltyCartResponseInterface
     */
    public function buyDiscountCodeProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->setSuccessResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $email = $customer->getEmail();

            // Call the new buyDiscountCode API endpoint
            $discountResult = $this->loyaltyengageCart->buyDiscountCode($email, $sku);

            if (!$discountResult) {
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    sprintf('Failed to buy discount code for SKU %s - API returned error', $sku),
                    ['email' => $email, 'sku' => $sku]
                );
                return $this->setErrorResponse($response, 'Failed to purchase discount code. Please check your available coins.');
            }

            $discountCode = $discountResult['discountCode'] ?? null;
            $discountPercentage = $discountResult['discountPercentage'] ?? null;
            $discountAmount = $discountResult['discountAmount'] ?? null;
            $spentCoins = $discountResult['spentCoins'] ?? 0;
            $availableCoins = $discountResult['availableCoins'] ?? 0;

            if (!$discountCode) {
                return $this->setErrorResponse($response, 'No discount code returned from LoyaltyEngage.');
            }

            if (strlen($discountCode) > 255) {
                return $this->setErrorResponse($response, 'Discount code is too long for Magento.');
            }

            // Determine discount type and amount
            // If discountPercentage is set, use percentage discount
            // If discountAmount is set, use fixed amount discount
            $usePercentage = $discountPercentage !== null && $discountPercentage > 0;
            $discountValue = $usePercentage ? $discountPercentage : ($discountAmount ?? 0);

            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Discount code purchased: %s (%.2f%s)', $discountCode, $discountValue, $usePercentage ? '%' : ' fixed'),
                [
                    'email' => $email,
                    'sku' => $sku,
                    'discount_code' => $discountCode,
                    'discount_percentage' => $discountPercentage,
                    'discount_amount' => $discountAmount,
                    'spent_coins' => $spentCoins,
                    'available_coins' => $availableCoins
                ]
            );

            // Create Magento cart rule - use percentage if available, otherwise fixed amount
            $finalCode = $this->ensureCartRuleExists($discountCode, $discountValue, !$usePercentage);

            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf('Attempting to apply coupon %s to cart for customer %d', $finalCode, $customerId),
                ['coupon_code' => $finalCode, 'customer_id' => $customerId]
            );

            // Apply coupon to cart
            $quote = $this->getOrCreateCustomerQuote($customerId);
            
            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf('Got quote ID %s for customer %d, applying coupon %s', $quote->getId(), $customerId, $finalCode),
                ['quote_id' => $quote->getId(), 'coupon_code' => $finalCode]
            );
            
            $quote->setCouponCode($finalCode);
            $quote->collectTotals()->save();
            
            // Verify the coupon was applied
            $appliedCoupon = $quote->getCouponCode();
            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Coupon application result - Requested: %s, Applied: %s', $finalCode, $appliedCoupon ?: 'NONE'),
                [
                    'requested_coupon' => $finalCode,
                    'applied_coupon' => $appliedCoupon,
                    'quote_id' => $quote->getId(),
                    'discount_amount' => $quote->getShippingAddress()->getDiscountAmount()
                ]
            );

            $discountTypeText = $usePercentage ? "{$discountPercentage}%" : "€{$discountAmount}";
            return $this->setSuccessResponse(
                $response, 
                "Discount code '{$finalCode}' ({$discountTypeText}) applied successfully. You spent {$spentCoins} coins."
            );
        } catch (\Exception $e) {
            $this->logger->critical('[LoyaltyShop] Exception in buyDiscountCodeProduct', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->setErrorResponse($response, 'An unexpected error occurred while applying the discount.');
        }
    }

}
