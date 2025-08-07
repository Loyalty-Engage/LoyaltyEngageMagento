<?php
namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\CacheInterface;
use Magento\Customer\Model\Session as CustomerSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
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
     * @var array In-memory cache for current request
     */
    private $memoryCache = [];

    /**
     * @param Curl $curl
     * @param CacheInterface $cache
     * @param CustomerSession $customerSession
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        CacheInterface $cache,
        CustomerSession $customerSession,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->cache = $cache;
        $this->customerSession = $customerSession;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
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
            $this->logger->debug('[LOYALTY-TIER] Free shipping feature disabled in admin - skipping API calls');
            return false;
        }

        // Get customer email
        if (!$customerEmail) {
            $customerEmail = $this->getCustomerEmail();
        }

        if (!$customerEmail) {
            $this->logger->debug('[LOYALTY-TIER] No customer email available for free shipping check');
            return false;
        }

        // Get customer's current tier (this may make API call)
        $currentTier = $this->getCustomerTier($customerEmail);
        if (!$currentTier) {
            $this->logger->debug('[LOYALTY-TIER] No tier found for customer: ' . $customerEmail);
            return false;
        }

        // Check if tier qualifies for free shipping
        $qualifyingTiers = $this->loyaltyHelper->getFreeShippingTiersArray();
        $qualifies = in_array($currentTier, $qualifyingTiers, true);

        $this->logger->info(sprintf(
            '[LOYALTY-TIER] Free shipping check for %s: tier=%s, qualifies=%s',
            $customerEmail,
            $currentTier,
            $qualifies ? 'YES' : 'NO'
        ));

        return $qualifies;
    }

    /**
     * Get customer's loyalty tier with aggressive caching
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

        // Level 2: Persistent cache (Redis/File)
        $cacheKey = 'loyalty_tier_' . md5($customerEmail);
        $cachedTier = $this->cache->load($cacheKey);
        
        if ($cachedTier !== false) {
            $this->memoryCache[$customerEmail] = $cachedTier;
            return $cachedTier ?: null;
        }

        // Level 3: API call (only if not cached)
        $tier = $this->fetchTierFromAPI($customerEmail);
        
        // Cache the result (even if null/empty)
        $cacheDuration = $this->loyaltyHelper->getTierCacheDuration();
        $this->cache->save($tier ?: '', $cacheKey, ['loyalty_tier'], $cacheDuration);
        $this->memoryCache[$customerEmail] = $tier;

        return $tier;
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
            $this->logger->warning('[LOYALTY-TIER] API URL not configured');
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

            // Enhanced logging - Log full API request/response details
            $this->logger->info(sprintf(
                '[LOYALTY-TIER] API Request - URL: %s, Customer: %s, HTTP Code: %d',
                $endpoint,
                $customerEmail,
                $httpCode
            ));

            $this->logger->info(sprintf(
                '[LOYALTY-TIER] API Response - Customer: %s, Response: %s',
                $customerEmail,
                $response
            ));

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                
                if (isset($data['currentTier'])) {
                    $this->logger->info(sprintf(
                        '[LOYALTY-TIER] Tier successfully parsed for %s: %s',
                        $customerEmail,
                        $data['currentTier']
                    ));
                    return $data['currentTier'];
                } else {
                    $this->logger->warning(sprintf(
                        '[LOYALTY-TIER] No currentTier field in response for %s. Full response: %s',
                        $customerEmail,
                        $response
                    ));
                }
            } else {
                $this->logger->error(sprintf(
                    '[LOYALTY-TIER] API error for %s - HTTP %d: %s',
                    $customerEmail,
                    $httpCode,
                    $response
                ));
            }

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[LOYALTY-TIER] Exception fetching tier for %s: %s',
                $customerEmail,
                $e->getMessage()
            ));
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
