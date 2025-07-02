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
        protected CouponCollectionFactory $couponCollectionFactory
    ) {}

    public function addProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();

        if (!$customerId || !$sku) {
            return $this->setErrorResponse($response, 'customerId and SKU are required.');
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $email = $customer->getEmail();

            $apiResponse = $this->loyaltyengageCart->addToCart($email, $sku);

            if ($apiResponse !== self::HTTP_OK) {
                return $this->setErrorResponse($response, 'Product could not be added. User is not eligible.');
            }

            $product = $this->productRepository->get($sku);
            if (!$this->isValidProduct($product)) {
                return $this->setErrorResponse($response, 'Invalid or unavailable product.');
            }

            $quote = $this->getOrCreateCustomerQuote($customerId);
            
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
            }

            $quote->collectTotals()->save();


            return $this->setSuccessResponse($response, 'Product added to loyalty cart successfully.');
        } catch (\Throwable $e) {
            $this->logger->critical('[LoyaltyShop] Exception in addProduct', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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

    try {
        $customer = $this->customerRepository->getById($customerId);
        $email = $customer->getEmail();

        $cartStatus = $this->loyaltyengageCart->addToCart($email, $sku);
        if ($cartStatus !== 200) {
            return $this->setErrorResponse($response, 'Failed to add product to loyalty cart.');
        }

        $sendToLoyaltyEngage = $discount > 1000
            ? ((float)substr((string)$discount, -2)) / 100  // bijv. 1015 → 15 → 0.15
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
            ? (float)substr((string)$discount, -2) // bijv. 1015 → 15 euro
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
