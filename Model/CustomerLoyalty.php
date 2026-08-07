<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\CustomerLoyaltyInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\Data\AttributeInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CustomerLoyalty implements CustomerLoyaltyInterface
{
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var CustomerLoyaltyUpdateResponseInterfaceFactory
     */
    private $responseFactory;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomerLoyaltyUpdateResponseInterfaceFactory $responseFactory,
        LoyaltyHelper $loyaltyHelper,
        CustomerLoyaltyDataProvider $customerLoyaltyDataProvider,
        AttributeInterfaceFactory $attributeFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->customerRepository = $customerRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->responseFactory = $responseFactory;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->customerLoyaltyDataProvider = $customerLoyaltyDataProvider;
        $this->attributeFactory = $attributeFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * @var CustomerLoyaltyDataProvider
     */
    private $customerLoyaltyDataProvider;

    /**
     * @var AttributeInterfaceFactory
     */
    private $attributeFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @inheritdoc
     */
    public function updateCustomerLoyaltyData(
        string $email,
        ?string $leCurrentTier = null,
        ?int $lePoints = null,
        ?int $leAvailableCoins = null,
        ?string $leNextTier = null,
        ?int $lePointsToNextTier = null,
        ?int $leReservedCoins = null,
        ?int $leExpiringPoints30d = null,
        ?string $storeCode = null
    ): CustomerLoyaltyUpdateResponseInterface {
        $response = $this->responseFactory->create();

        try {
            // Find customer by email
            $customer = $this->getCustomerByEmail($email, $storeCode);
            
            if (!$customer) {
                $response->setSuccess(true);
                $response->setMessage('No action taken - customer not found');
                $response->setCustomerId(null);
                $response->setUpdatedFields([]);
                return $response;
            }

            $updatedFields = [];
            $storeId = $this->customerLoyaltyDataProvider->resolveStoreId($storeCode);
            $storeScopedData = [];

            // Update loyalty attributes if provided
            if ($leCurrentTier !== null) {
                $storeScopedData['le_current_tier'] = $leCurrentTier;
                $updatedFields[] = 'le_current_tier';
            }

            if ($lePoints !== null) {
                $storeScopedData['le_points'] = $lePoints;
                $updatedFields[] = 'le_points';
            }

            if ($leAvailableCoins !== null) {
                $storeScopedData['le_available_coins'] = $leAvailableCoins;
                $updatedFields[] = 'le_available_coins';
            }

            if ($leNextTier !== null) {
                $storeScopedData['le_next_tier'] = $leNextTier;
                $updatedFields[] = 'le_next_tier';
            }

            if ($lePointsToNextTier !== null) {
                $storeScopedData['le_points_to_next_tier'] = $lePointsToNextTier;
                $updatedFields[] = 'le_points_to_next_tier';
            }

            if ($leReservedCoins !== null) {
                $storeScopedData['le_reserved_coins'] = $leReservedCoins;
                $updatedFields[] = 'le_reserved_coins';
            }

            if ($leExpiringPoints30d !== null) {
                $storeScopedData['le_expiring_points_30d'] = $leExpiringPoints30d;
                $updatedFields[] = 'le_expiring_points_30d';
            }

            // Save customer if any fields were updated
            if (!empty($updatedFields)) {
                $this->customerLoyaltyDataProvider->saveCustomerStoreData(
                    (int) $customer->getId(),
                    $storeId,
                    $storeScopedData
                );

                if ($this->shouldSyncGlobalFallback($storeId)) {
                    foreach ($storeScopedData as $attributeCode => $value) {
                        $customer->setCustomAttribute($attributeCode, $value);
                    }
                    $this->customerRepository->save($customer);
                }
                
                $response->setSuccess(true);
                $response->setMessage('Customer loyalty data updated successfully');
                $response->setCustomerId((int) $customer->getId());
                $response->setUpdatedFields($updatedFields);
                
                $this->loyaltyHelper->log(
                    'info',
                    'CustomerLoyalty',
                    'updateCustomerLoyaltyData',
                    'Customer loyalty data updated',
                    [
                        'customer_id' => $customer->getId(),
                        'email' => $email,
                        'store_id' => $storeId,
                        'store_code' => $storeCode,
                        'updated_fields' => $updatedFields
                    ]
                );
            } else {
                $response->setSuccess(true);
                $response->setMessage('No fields provided for update');
                $response->setCustomerId((int) $customer->getId());
                $response->setUpdatedFields([]);
            }

        } catch (LocalizedException $e) {
            $this->loyaltyHelper->log(
                'error',
                'CustomerLoyalty',
                'updateCustomerLoyaltyData',
                'Error updating customer loyalty data: ' . $e->getMessage(),
                [
                    'email' => $email,
                    'exception' => $e
                ]
            );
            
            $response->setSuccess(false);
            $response->setMessage('Error updating customer loyalty data: ' . $e->getMessage());
            $response->setCustomerId(null);
            $response->setUpdatedFields([]);
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'CustomerLoyalty',
                'updateCustomerLoyaltyData',
                'Unexpected error updating customer loyalty data: ' . $e->getMessage(),
                [
                    'email' => $email,
                    'exception' => $e
                ]
            );
            
            $response->setSuccess(false);
            $response->setMessage('Unexpected error occurred while updating customer loyalty data');
            $response->setCustomerId(null);
            $response->setUpdatedFields([]);
        }

        return $response;
    }

    /**
     * Get customer by email address
     *
     * @param string $email
     * @param string|null $storeCode
     * @return CustomerInterface|null
     * @throws LocalizedException
     */
    private function getCustomerByEmail(string $email, ?string $storeCode = null): ?CustomerInterface
    {
        try {
            $customer = $this->customerLoyaltyDataProvider->getCustomerByEmail($email, $storeCode);
            if ($customer) {
                return $customer;
            }

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('email', $email)
                ->addFilter('website_id', $this->customerLoyaltyDataProvider->resolveWebsiteId($storeCode))
                ->setPageSize(1)
                ->create();

            $customers = $this->customerRepository->getList($searchCriteria);
            
            if ($customers->getTotalCount() > 0) {
                $items = $customers->getItems();
                return reset($items);
            }
            
            return null;
        } catch (LocalizedException $e) {
            $this->loyaltyHelper->log(
                'error',
                'CustomerLoyalty',
                'getCustomerByEmail',
                'Error searching for customer by email: ' . $e->getMessage(),
                [
                    'email' => $email,
                    'exception' => $e
                ]
            );
            throw $e;
        }
    }

    private function shouldSyncGlobalFallback(int $storeId): bool
    {
        $store = $this->storeManager->getStore($storeId);
        $website = $store->getWebsite();

        return (int) $website->getDefaultStore()->getId() === $storeId;
    }
}
