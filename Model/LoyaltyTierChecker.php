<?php
namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;

class LoyaltyTierChecker
{
    /**
     * @var ApiClient
     */
    private $apiClient;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var array In-memory cache for current request
     */
    private $memoryCache = [];

    /**
     * @param ApiClient $apiClient
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        ApiClient $apiClient,
        CustomerRepositoryInterface $customerRepository,
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->apiClient = $apiClient;
        $this->customerRepository = $customerRepository;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Check if customer qualifies for free shipping based on loyalty tier
     * Ultra-lightweight with multi-level caching and admin control
     *
     * @param string|null $customerEmail
     */
    public function qualifiesForFreeShipping(?string $customerEmail = null): bool
    {
        // Early exit if module or free shipping is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return false;
        }

        // CRITICAL: Check if free shipping feature is enabled in admin
        if (!$this->loyaltyHelper->isFreeShippingEnabled()) {
            return false;
        }

        // Get customer email
        if (!$customerEmail) {
            $customerSessionData = $this->loyaltyHelper->getCustomerSession();
            if ($customerSessionData) {
                $customerEmail = $customerSessionData->getEmail();
            } else {
                return false;
            }
        }

        // Get customer's current tier (this may make API call)
        $currentTier = $this->getCustomerTier($customerEmail);
        if (!$currentTier) {
            return false;
        }

        // Check if tier qualifies for free shipping
        $qualifyingTiers = $this->loyaltyHelper->getFreeShippingTiersArray();
        $qualifies = in_array($currentTier, $qualifyingTiers, true);

        // Only log if debug is enabled (uses masked email for privacy)
        $this->loyaltyHelper->log(
            'debug',
            LoyaltyLogger::COMPONENT_PLUGIN,
            'FREE-SHIPPING',
            sprintf(
                'Free shipping check for %s: tier=%s, qualifies=%s',
                $this->loyaltyHelper->logMaskedEmail($customerEmail),
                $currentTier,
                $qualifies ? 'YES' : 'NO'
            )
        );

        return $qualifies;
    }

    /**
     * Get customer's loyalty tier from local customer attributes
     *
     * @param string $customerEmail
     * @return string|null
     */
    public function getCustomerTier(string $customerEmail): ?string
    {
        // Level 1: In-memory cache (fastest)
        if (isset($this->memoryCache[$customerEmail])) {
            return $this->memoryCache[$customerEmail];
        }

        // Level 2: Get from local customer attributes (much faster than API)
        $tier = $this->fetchTierFromCustomerAttributes($customerEmail);
        
        // Cache the result in memory for this request
        $this->memoryCache[$customerEmail] = $tier;

        return $tier;
    }

    /**
     * Fetch customer tier from local customer attributes
     *
     * @param string $customerEmail
     * @return string|null
     */
    private function fetchTierFromCustomerAttributes(string $customerEmail): ?string
    {
        $customerSession = $this->loyaltyHelper->getCustomerSession();
        try {
            // First try to get from current session if it's the same customer
            if ($customerSession && $customerSession->getEmail() === $customerEmail) {
                $tier = $customerSession->getData('le_current_tier');
                if ($tier) {
                    return $tier;
                }
            }
            $customerId = $customerSession ? $customerSession->getId() : null;
            // Get customer by email from repository
            $customer = $this->loyaltyHelper->getCustomerDataById($customerId);
            
            // Get attribute value - handle both model and data objects
            $tier = null;
            if (isset($customer['customer']) && $customer['customer'] instanceof \Magento\Customer\Model\Customer) {
                // Customer model object
                $tier = $customer['customer']->getData('le_current_tier');
            } elseif (isset($customer['customer']) && $customer['customer'] instanceof \Magento\Customer\Api\Data\CustomerInterface) {
                // Customer data object from repository
                $attribute = $customer['customer']->getCustomAttribute('le_current_tier');
                if ($attribute) {
                    $tier = $attribute->getValue();
                }
            }

            return $tier ?: null;

        } catch (\Exception $e) {
            // Only log errors (not routine operations)
            $this->loyaltyHelper->log(
                'error',
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_ERROR,
                sprintf(
                    'Exception fetching tier from attributes for %s: %s',
                    $this->loyaltyHelper->logMaskedEmail($customerEmail),
                    $e->getMessage()
                )
            );
            
            // Fallback to API if local data fails
            return $this->fetchTierFromAPI($customerEmail);
        }
    }

    /**
     * Fetch customer tier from LoyaltyEngage API
     *
     * @param string $customerEmail
     * @return string|null
     */
    private function fetchTierFromAPI(string $customerEmail): ?string
    {
        $apiUrl = $this->loyaltyHelper->getApiUrl();
        $hashedEmail = $this->loyaltyHelper->hashEmail($customerEmail);
        if (!$apiUrl) {
            return null;
        }

        try {
            // Prepare API endpoint
            $endpoint = rtrim($apiUrl, '/') . '/api/v1/contact/' . $hashedEmail . '/loyalty_status';

            // Use ApiClient service for consistent API calls
            $response = $this->apiClient->get($endpoint);
            
            if (isset($response['currentTier'])) {

                $this->loyaltyHelper->log(
                    'debug',
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_SUCCESS,
                    sprintf(
                        'Tier fetched successfully for %s',
                        $this->loyaltyHelper->logMaskedEmail($customerEmail)
                    ),
                    [
                        'tier' => $response['currentTier'],
                        'response' => $response
                    ]
                );

                return $response['currentTier'];

            } else {
                // Only log API errors (not successful requests)
                $this->loyaltyHelper->log(
                    'error',
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    sprintf(
                        'API error for %s - Invalid response format',
                        $this->loyaltyHelper->logMaskedEmail($customerEmail)
                    ),
                    ['response' => $response]
                );
            }
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_ERROR,
                sprintf(
                    'Exception fetching tier for %s: %s',
                    $this->loyaltyHelper->logMaskedEmail($customerEmail),
                    $e->getMessage()
                ),
                [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
        }

        return null;
    }
}
