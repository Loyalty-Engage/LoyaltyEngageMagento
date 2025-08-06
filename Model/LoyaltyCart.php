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
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as CouponCollectionFactory;
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
        protected LoyaltyHelper $loyaltyHelper
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

    private function setSuccessResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        return $response->setSuccess(true)->setMessage($message);
    }

    private function setErrorResponse(LoyaltyCartResponseInterface $response, string $message): LoyaltyCartResponseInterface
    {
        $this->response->setHttpResponseCode(self::HTTP_BAD_REQUEST);
        return $response->setSuccess(false)->setMessage($message);
    }

    public function ensureCartRuleExists(?string $code, float $discountRate, bool $forceCartFixed = false): string
    {
        try {
            if (empty($code)) {
                $code = 'LOYALTY-' . strtoupper(bin2hex(random_bytes(4)));
            }

            $couponCollection = $this->couponCollectionFactory->create()
                ->addFieldToFilter('code', $code);

            if ($couponCollection->getSize() > 0) {
                return $code;
            }

            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            $customerGroupIds = [1, 2, 3];

            $rule = $this->ruleFactory->create();
            $rule->setName('LoyaltyEngage Auto Rule ' . $code)
                ->setDescription('Auto-generated from LoyaltyEngage')
                ->setFromDate(date('Y-m-d'))
                ->setIsActive(1)
                ->setSimpleAction($forceCartFixed ? 'cart_fixed' : 'by_percent') // 🔥 Belangrijk
                ->setDiscountAmount($discountRate) // 🔥 Hier moet 50 staan, niet 0.5 of 277
                ->setStopRulesProcessing(0)
                ->setIsAdvanced(1)
                ->setUsesPerCustomer(1)
                ->setCustomerGroupIds($customerGroupIds)
                ->setCouponType(2)
                ->setCouponCode($code)
                ->setUsesPerCoupon(1)
                ->setWebsiteIds([$websiteId]);

            $rule->save();

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

    public function claimDiscountAfterAddToLoyaltyCart(int $customerId, float $discount, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            // Return a successful response, as no error occurred, but no action was taken.
            return $this->setSuccessResponse($response, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $email = $customer->getEmail();

            $cartStatus = $this->loyaltyengageCart->addToCart($email, $sku);
            if ($cartStatus !== 200) {
                return $this->setErrorResponse($response, 'Failed to add product to loyalty cart.');
            }

            $sendToLoyaltyEngage = $discount > 1000
                ? ((float) substr((string) $discount, -2)) / 100  // bijv. 1015 → 15 → 0.15
                : $discount;

            $discountResult = $this->loyaltyengageCart->claimDiscount($email, $sendToLoyaltyEngage);

            $discountCode = $discountResult['discountCode'] ?? null;
            if (!$discountCode) {
                return $this->setErrorResponse($response, 'No discount code returned from LoyaltyEngage.');
            }

            if (strlen($discountCode) > 255) {
                return $this->setErrorResponse($response, 'Discount code is too long for Magento.');
            }

            $discountAmount = $discount > 1000
                ? (float) substr((string) $discount, -2) // bijv. 1015 → 15 euro
                : $discount;
            $finalCode = $this->ensureCartRuleExists($discountCode, $discountAmount, true); // forceer cart_fixed

            $quote = $this->getOrCreateCustomerQuote($customerId);
            $quote->setCouponCode($finalCode);
            $quote->collectTotals()->save();

            return $this->setSuccessResponse($response, "Product added and discount code '{$finalCode}' applied.");
        } catch (\Exception $e) {
            $this->logger->critical('[LoyaltyShop] Exception in claimDiscountAfterAddToLoyaltyCart', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->setErrorResponse($response, 'An unexpected error occurred while applying the discount.');
        }
    }

}
