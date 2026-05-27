<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemRemoveApiInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class LoyaltyCartItemRemove implements LoyaltyCartItemRemoveApiInterface
{
    /**
     * LoyaltyCartItemRemove Construct
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
     * RemoveProduct function
     *
     * @param string $sku
     * @param int $customerId
     * @param int $quantity
     * @return LoyaltyCartResponseInterface
     */
    public function removeProduct(string $sku, int $customerId, int $quantity): LoyaltyCartResponseInterface
    {
        $responseItem = $this->loyaltyCartResponseFactory->create();

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return $this->loyaltyHelper->successResponse($responseItem, 'LoyaltyEngage module is disabled. No action taken.');
        }

        try {
            $customer        = $this->customerRepository->getById($customerId);
            $email           = $customer->getEmail();
            $hashedEmail     = $this->loyaltyHelper->hashEmail($email);
            $quote           = $this->cartRepository->getActiveForCustomer($customer->getId());
            $quoteFullObject = $this->cartRepository->get($quote->getId());
            $items           = $quoteFullObject->getAllItems();

            $response = $this->loyaltyengageCart->removeItem($hashedEmail, $sku, $quantity);

            if ($response !== LoyaltyHelper::HTTP_OK) {
                return $this->loyaltyHelper->errorResponse(
                    $responseItem,
                    'Product could not be removed. User is not eligible.',
                    'api_error',
                    LoyaltyHelper::HTTP_BAD_REQUEST
                );
            }

            foreach ($items as $item) {
                if ($item->getSku() === $sku) {
                    $currentQty = $item->getQty();
                    if ($currentQty > $quantity) {
                        $item->setQty($currentQty - $quantity);
                    } else {
                        $quoteFullObject->removeItem($item->getId());
                    }

                    $quoteFullObject->collectTotals();
                    $this->cartRepository->save($quoteFullObject);
                    break;
                }
            }

            return $this->loyaltyHelper->successResponse($responseItem, 'Product removed successfully.');

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
