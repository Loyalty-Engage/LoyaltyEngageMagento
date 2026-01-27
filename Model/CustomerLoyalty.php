<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\CustomerLoyaltyInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CustomerLoyaltyUpdateResponseInterfaceFactory $responseFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CustomerLoyaltyUpdateResponseInterfaceFactory $responseFactory,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->responseFactory = $responseFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function updateCustomerLoyaltyData(
        string $email,
        ?string $leCurrentTier = null,
        ?int $lePoints = null,
        ?int $leAvailableCoins = null,
        ?string $leNextTier = null,
        ?int $lePointsToNextTier = null
    ): CustomerLoyaltyUpdateResponseInterface {
        $response = $this->responseFactory->create();

        try {
            // Find customer by email
            $customer = $this->getCustomerByEmail($email);
            
            if (!$customer) {
                $response->setSuccess(true);
                $response->setMessage('No action taken - customer not found');
                $response->setCustomerId(null);
                $response->setUpdatedFields([]);
                return $response;
            }

            $updatedFields = [];

            // Update loyalty attributes if provided
            if ($leCurrentTier !== null) {
                $customer->setCustomAttribute('le_current_tier', $leCurrentTier);
                $updatedFields[] = 'le_current_tier';
            }

            if ($lePoints !== null) {
                $customer->setCustomAttribute('le_points', $lePoints);
                $updatedFields[] = 'le_points';
            }

            if ($leAvailableCoins !== null) {
                $customer->setCustomAttribute('le_available_coins', $leAvailableCoins);
                $updatedFields[] = 'le_available_coins';
            }

            if ($leNextTier !== null) {
                $customer->setCustomAttribute('le_next_tier', $leNextTier);
                $updatedFields[] = 'le_next_tier';
            }

            if ($lePointsToNextTier !== null) {
                $customer->setCustomAttribute('le_points_to_next_tier', $lePointsToNextTier);
                $updatedFields[] = 'le_points_to_next_tier';
            }

            // Save customer if any fields were updated
            if (!empty($updatedFields)) {
                $this->customerRepository->save($customer);
                
                $response->setSuccess(true);
                $response->setMessage('Customer loyalty data updated successfully');
                $response->setCustomerId((int) $customer->getId());
                $response->setUpdatedFields($updatedFields);
                
                $this->logger->info('Customer loyalty data updated', [
                    'customer_id' => $customer->getId(),
                    'email' => $email,
                    'updated_fields' => $updatedFields
                ]);
            } else {
                $response->setSuccess(true);
                $response->setMessage('No fields provided for update');
                $response->setCustomerId((int) $customer->getId());
                $response->setUpdatedFields([]);
            }

        } catch (LocalizedException $e) {
            $this->logger->error('Error updating customer loyalty data: ' . $e->getMessage(), [
                'email' => $email,
                'exception' => $e
            ]);
            
            $response->setSuccess(false);
            $response->setMessage('Error updating customer loyalty data: ' . $e->getMessage());
            $response->setCustomerId(null);
            $response->setUpdatedFields([]);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error updating customer loyalty data: ' . $e->getMessage(), [
                'email' => $email,
                'exception' => $e
            ]);
            
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
     * @return CustomerInterface|null
     * @throws LocalizedException
     */
    private function getCustomerByEmail(string $email): ?CustomerInterface
    {
        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('email', $email)
                ->create();

            $customers = $this->customerRepository->getList($searchCriteria);
            
            if ($customers->getTotalCount() > 0) {
                $items = $customers->getItems();
                return reset($items);
            }
            
            return null;
        } catch (LocalizedException $e) {
            $this->logger->error('Error searching for customer by email: ' . $e->getMessage(), [
                'email' => $email,
                'exception' => $e
            ]);
            throw $e;
        }
    }
}
