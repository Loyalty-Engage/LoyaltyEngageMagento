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


class LoyaltyCart implements LoyaltyCartInterface
{

    protected const HTTP_OK = 200;
    protected const HTTP_BAD_REQUEST = 401;

    /**
     * Constructor
     *
     * @param CartItemInterfaceFactory $cartItemFactory
     * @param CartManagementInterface $cartManagement
     * @param CartRepositoryInterface $quoteRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param Curl $curl
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param ProductRepository $productRepository
     * @param RestRequest $request
     * @param Response $response
     * @param ScopeConfigInterface $scopeConfig
     * @param LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory
     * @param StoreManagerInterface $storeManager
     * @param Session $customerSession
     */
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
        protected Session $customerSession
    ) {
    }

    /**
     * Add a product to the cart using loyalty points.
     *
     * @param int $customerId
     * @param string $sku
     * @return LoyaltyCartResponseInterface
     * @throws LocalizedException
     */
    public function addProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        $response = $this->loyaltyCartResponseFactory->create();
        if (!$customerId || !$sku) {
            return $this->setErrorResponse($response, 'customerId and SKU');
        }
       
        $customer = $this->customerRepository->getById($customerId);
        $apiResponse = $this->loyaltyengageCart->addToCart($customer->getEmail(), $sku);
        if ($apiResponse !==self::HTTP_OK) {
            return $this->setErrorResponse($response, 'Product could not be added. User is not eligible.');
        }
        
        try {
            $product = $this->productRepository->get($sku);
            if (!$this->isValidProduct($product)) {
                return $this->setErrorResponse($response, 'Invalid or unavailable product.');
            }
            $quote = $this->getOrCreateCustomerQuote($customerId);
            $quoteItem = $quote->addProduct($product);
 
            // Set the price of the quote item to 0
            $quoteItem->setCustomPrice(0);
            $quoteItem->setOriginalCustomPrice(0);

            $quoteItem->addOption([
                'code' => 'loyalty_locked_qty',
                'value' => 1
            ]);
 
            $quote->collectTotals()->save();

            return $this->setSuccessResponse($response, 'Product added to loyalty cart successfully.');
        } catch (\Exception $e) {
            return $this->setErrorResponse($response, $e->getMessage());
        }
    }

    /**
     * Validates salable, enabled
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function isValidProduct($product): bool
    {
        return $product->isSalable()
            && $product->getStatus() == 1;
    }

    /**
     * Retrieves customerquote, or createsnew
     *
     * @param int $customerId
     * @return \Magento\Quote\Api\Data\CartInterface
     */
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
     * Sets a success response
     *
     * @param LoyaltyCartResponseInterface $response
     * @param string $message
     * @return LoyaltyCartResponseInterface
     */
    private function setSuccessResponse(
        LoyaltyCartResponseInterface $response,
        string $message
    ): LoyaltyCartResponseInterface {
        return $response->setSuccess(true)->setMessage($message);
    }

    /**
     * Sets an error response
     *
     * @param LoyaltyCartResponseInterface $response
     * @param string $message
     * @return LoyaltyCartResponseInterface
     */
    private function setErrorResponse(
        LoyaltyCartResponseInterface $response,
        string $message
    ): LoyaltyCartResponseInterface {
        $this->response->setHttpResponseCode(self::HTTP_BAD_REQUEST);
        return $response->setSuccess(false)->setMessage($message);
    }
}
