<?php
namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\CacheInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Psr\Log\LoggerInterface;

class LoyaltyTierChecker
{
    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var array In-memory cache for current request
     */
    private $memoryCache = [];

    /**
     * @param Curl $curl
     * @param CacheInterface $cache
     * @param CustomerSession $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        Curl $curl,
        CacheInterface $cache,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->curl = $curl;
        $this->cache = $cache;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Check if customer qualifies for free shipping based on loyalty tier
     * Ultra-lightweight with multi-level caching and admin control
     *
     * @param string|null $customerEmail
     * @return bool
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
            $customerEmail = $this->getCustomerEmail();
        }

        if (!$customerEmail) {
            return false;
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
        if ($this->loyaltyLogger->isDebugEnabled()) {
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_PLUGIN,
                'FREE-SHIPPING',
                sprintf(
                    'Free shipping check for %s: tier=%s, qualifies=%s',
                    $this->loyaltyLogger->maskEmail($customerEmail),
                    $currentTier,
                    $qualifies ? 'YES' : 'NO'
                )
            );
        }

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
        try {
            // First try to get from current session if it's the same customer
            if ($this->customerSession->isLoggedIn() && 
                $this->customerSession->getCustomer()->getEmail() === $customerEmail) {
                
                $customer = $this->customerSession->getCustomer();
                $tier = $customer->getData('le_current_tier');
                
                if ($tier) {
                    return $tier;
                }
            }

            // Get customer by email from repository
            $customer = $this->customerRepository->get($customerEmail);
            
            // Get attribute value - handle both model and data objects
            $tier = null;
            if ($customer instanceof \Magento\Customer\Model\Customer) {
                // Customer model object
                $tier = $customer->getData('le_current_tier');
            } elseif ($customer instanceof \Magento\Customer\Api\Data\CustomerInterface) {
                // Customer data object from repository
                $attribute = $customer->getCustomAttribute('le_current_tier');
                if ($attribute) {
                    $tier = $attribute->getValue();
                }
            }

            return $tier ?: null;

        } catch (\Exception $e) {
            // Only log errors (not routine operations)
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_ERROR,
                sprintf(
                    'Exception fetching tier from attributes for %s: %s',
                    $this->loyaltyLogger->maskEmail($customerEmail),
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
        if (!$apiUrl) {
            return null;
        }

        try {
            // Prepare API endpoint
            $endpoint = rtrim($apiUrl, '/') . '/api/v1/contact/' . urlencode($customerEmail) . '/loyalty_status';

            // Set headers with Basic Authentication (same as working cart code)
            $tenantId = $this->loyaltyHelper->getClientId(); // Actually tenant_id
            $bearerToken = $this->loyaltyHelper->getClientSecret(); // Actually bearer_token
            
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            
            if ($tenantId && $bearerToken) {
                $authString = base64_encode($tenantId . ':' . $bearerToken);
                $headers['Authorization'] = 'Basic ' . $authString;
            }

            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(5); // Short timeout for performance
            
            // Make API call
            $this->curl->get($endpoint);
            
            $httpCode = $this->curl->getStatus();
            $response = $this->curl->getBody();

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                
                if (isset($data['currentTier'])) {
                    return $data['currentTier'];
                }
            } else {
                // Only log API errors (not successful requests)
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    sprintf(
                        'API error for %s - HTTP %d',
                        $this->loyaltyLogger->maskEmail($customerEmail),
                        $httpCode
                    )
                );
            }

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_API,
                LoyaltyLogger::ACTION_ERROR,
                sprintf(
                    'Exception fetching tier for %s: %s',
                    $this->loyaltyLogger->maskEmail($customerEmail),
                    $e->getMessage()
                )
            );
        }

        return null;
    }

    /**
     * Get current customer email (lightweight method)
     *
     * @return string|null
     */
    private function getCustomerEmail(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        return $this->customerSession->getCustomer()->getEmail();
    }

    /**
     * Clear tier cache for specific customer
     *
     * @param string $customerEmail
     * @return void
     */
    public function clearTierCache(string $customerEmail): void
    {
        $cacheKey = 'loyalty_tier_' . md5($customerEmail);
        $this->cache->remove($cacheKey);
        unset($this->memoryCache[$customerEmail]);
    }

    /**
     * Clear all tier caches
     *
     * @return void
     */
    public function clearAllTierCaches(): void
    {
        $this->cache->clean(['loyalty_tier']);
        $this->memoryCache = [];
    }
}
