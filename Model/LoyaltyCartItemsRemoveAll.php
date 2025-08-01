<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemsRemoveApiInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteRepository;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyCartItemsRemoveAll implements LoyaltyCartItemsRemoveApiInterface
{
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_OK = 200;

    /**
     * LoyaltyCartItemsRemoveAll Construct
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param Request $request
     * @param Response $response
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteRepository $quoteRepository
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
        protected Request $request,
        protected Response $response,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory,
        protected CartRepositoryInterface $cartRepository,
        protected QuoteRepository $quoteRepository,
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * RemoveAllProduct function
     *
     * @param int $customerId
     * @return LoyaltyCartResponseInterface
     */
    public function removeAllProduct(int $customerId): LoyaltyCartResponseInterface
    {
        $responseItem = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            // Return a successful response, as no error occurred, but no action was taken.
            return $this->setSuccessResponse($responseItem, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $quote = $this->cartRepository->getActiveForCustomer($customerId);
            $quoteFullObject = $this->quoteRepository->get($quote->getId());
            $items = $quoteFullObject->getAllItems();

            $response = $this->loyaltyengageCart->removeAllItem($customer->getEmail());

            if ($response !== self::HTTP_OK) {
                return $this->setErrorResponse(
                    $responseItem,
                    'Product could not be removed. User is not eligible.',
                    self::HTTP_BAD_REQUEST
                );
            }

            foreach ($items as $item) {
                $quoteFullObject->removeItem($item->getId());
            }

            $quoteFullObject->collectTotals();
            $quoteFullObject->save();

            return $this->setSuccessResponse($responseItem, 'Product removal notification sent successfully.');
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
