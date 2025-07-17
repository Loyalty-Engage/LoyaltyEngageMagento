<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemRemoveApiInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use Magento\Quote\Model\QuoteRepository;

class LoyaltyCartItemRemove implements LoyaltyCartItemRemoveApiInterface
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_OK = 200;

    /**
     * LoyaltyCartItemRemove Construct
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param Request $request
     * @param Response $response
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteRepository $quoteRepository
     */
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
        protected Request $request,
        protected Response $response,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory,
        protected CartRepositoryInterface $cartRepository,
        protected QuoteRepository $quoteRepository,
    ) {
    }

    /**
     * RemoveProduct function
     *
     * @param string $sku
     * @param int $customerId
     * @param integer $quantity
     * @return LoyaltyCartResponseInterface
     */
    public function removeProduct(string $sku, int $customerId, int $quantity): LoyaltyCartResponseInterface
    {
        $responseItem = $this->loyaltyCartResponseFactory->create();

        try {

            $customer = $this->customerRepository->getById($customerId);
            $quote = $this->cartRepository->getActiveForCustomer($customer->getId());
            $quoteFullObject = $this->quoteRepository->get($quote->getId());
            $items = $quoteFullObject->getAllItems();
            $response = $this->loyaltyengageCart->removeItem($customer->getEmail(), $sku, $quantity);

            if ($response !== self::HTTP_OK) {
                return $this->setErrorResponse(
                    $responseItem,
                    'Product could not be removed. User is not eligible.',
                    self::HTTP_BAD_REQUEST
                );
            }
            foreach ($items as $item) {
                if ($item->getSku() === $sku) {
                    $currentQty = $item->getQty();
                    if ($currentQty > $quantity) {
                        // Reduce the quantity of the item
                        $item->setQty($currentQty - $quantity);
                    } else {
                        $quoteFullObject->removeItem($item->getId());
                    }

                    $quoteFullObject->collectTotals();
                    $quoteFullObject->save();
                    break;
                }
            }

            return $this->setSuccessResponse($responseItem, 'Product removed successfully.');
        } catch (\Exception $e) {
            return $this->setErrorResponse($responseItem, $e->getMessage(), self::HTTP_BAD_REQUEST);
        }
    }

    /**
     * SuccessResponse function
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
     * ErrorResponse function
     *
     * @param LoyaltyCartResponseInterface $response
     * @param string $message
     * @param int $httpCode
     * @return LoyaltyCartResponseInterface
     */
    private function setErrorResponse(
        LoyaltyCartResponseInterface $response,
        string $message,
        int $httpCode = self::HTTP_BAD_REQUEST
    ): LoyaltyCartResponseInterface {
        $this->response->setHttpResponseCode($httpCode);
        return $response->setSuccess(false)->setMessage($message);
    }
}
