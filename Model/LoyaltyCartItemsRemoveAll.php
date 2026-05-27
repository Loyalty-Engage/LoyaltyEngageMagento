<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemsRemoveApiInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyCartItemsRemoveAll implements LoyaltyCartItemsRemoveApiInterface
{
    /**
     * LoyaltyCartItemsRemoveAll Construct
     *
     * @param CustomerRepositoryInterface $customerRepository
     * @param Request $request
     * @param Response $response
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory
     * @param CartRepositoryInterface $cartRepository
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
        protected Request $request,
        protected Response $response,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected LoyaltyCartResponseInterfaceFactory $loyaltyCartResponseFactory,
        protected CartRepositoryInterface $cartRepository,
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
            return $this->loyaltyHelper->successResponse($responseItem, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customer        = $this->customerRepository->getById($customerId);
            $email           = $customer->getEmail();
            $hashedEmail     = $this->loyaltyHelper->hashEmail($email);
            $quote           = $this->cartRepository->getActiveForCustomer($customerId);
            $quoteFullObject = $this->cartRepository->get($quote->getId());
            $items           = $quoteFullObject->getAllItems();

            $response = $this->loyaltyengageCart->removeAllItem($hashedEmail);

            if ($response !== LoyaltyHelper::HTTP_OK) {
                return $this->loyaltyHelper->errorResponse(
                    $responseItem,
                    'Product could not be removed. User is not eligible.',
                    'api_error',
                    LoyaltyHelper::HTTP_BAD_REQUEST
                );
            }

            foreach ($items as $item) {
                $quoteFullObject->removeItem($item->getId());
            }

            $quoteFullObject->collectTotals();
            $this->cartRepository->save($quoteFullObject);

            return $this->loyaltyHelper->successResponse($responseItem, 'Product removal notification sent successfully.');

        } catch (\Exception $e) {
            return $this->loyaltyHelper->errorResponse(
                $responseItem,
                $e->getMessage(),
                'system_error',
                LoyaltyHelper::HTTP_BAD_REQUEST
            );
        }
    }
}
