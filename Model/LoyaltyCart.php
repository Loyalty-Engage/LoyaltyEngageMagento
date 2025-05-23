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
            $quoteItem = $quote->addProduct($product);
            $quoteItem->setCustomPrice(0);
            $quoteItem->setOriginalCustomPrice(0);
            $quoteItem->setData('loyalty_locked_qty', 1);
            $quoteItem->addOption(['code' => 'loyalty_locked_qty', 'value' => 1]);

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

    public function ensureCartRuleExists(?string $code, float $discountRate): string
    {
        try {

            if (empty($code)) {
                $code = 'LOYALTY-' . strtoupper(bin2hex(random_bytes(4)));
            }

            $couponCollection = $this->couponCollectionFactory->create()->addFieldToFilter('code', $code);

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
                ->setSimpleAction('by_percent')
                ->setDiscountAmount($discountRate)
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

            $discountResult = $this->loyaltyengageCart->claimDiscount($email, $discount);

            $discountCode = $discountResult['discountCode'] ?? null;
            $discountAmount = $discountResult['discount'] ?? $discount;

            if (!$discountCode) {
                return $this->setErrorResponse($response, 'No discount code returned from LoyaltyEngage.');
            }

            if (strlen($discountCode) > 255) {
                return $this->setErrorResponse($response, 'Discount code is too long for Magento.');
            }

            $finalCode = $this->ensureCartRuleExists($discountCode, $discountAmount);

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
