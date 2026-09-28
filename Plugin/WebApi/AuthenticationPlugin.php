<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\WebApi;

use Magento\Framework\Webapi\Rest\Request as RestRequest;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Authorization;

/**
 * Plugin to authenticate LoyaltyEngage API requests using Basic Auth
 *
 * This plugin validates that incoming requests to LoyaltyEngage endpoints
 * contain valid Basic Auth credentials matching the configured tenant_id and bearer_token.
 */
class AuthenticationPlugin
{
    private const ACL_RESOURCE = 'LoyaltyEngage_LoyaltyShop::api';

    /**
     * @var RestRequest
     */
    private $request;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @param RestRequest $request
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        RestRequest $request,
        LoyaltyHelper $loyaltyHelper,
        private \Magento\Store\Model\StoreManagerInterface $stores
    ) {
        $this->request = $request;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    public function aroundIsAllowed(Authorization $subject, callable $proceed, $aclResources): bool
    {
        if (!in_array(self::ACL_RESOURCE, (array) $aclResources, true)) {
            return $proceed($aclResources);
        }

        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            throw new AuthorizationException(__('LoyaltyEngage module is disabled.'));
        }

        if (!$this->validateBasicAuth()) {
            $this->loyaltyHelper->log(
                'error',
                'AuthenticationPlugin',
                'authorize',
                'Unauthorized API request attempt',
                [
                    'path' => $this->request->getPathInfo(),
                    'ip' => $this->request->getClientIp()
                ]
            );
            throw new AuthorizationException(__('Invalid or missing authentication credentials.'));
        }

        return true;
    }

    /**
     * Validate Basic Auth credentials from the request
     *
     * @return bool
     */
    private function validateBasicAuth(): bool
    {
        $authHeader = $this->request->getHeader('Authorization');
        
        if (empty($authHeader)) {
            return false;
        }

        // Check for Basic Auth
        if (stripos($authHeader, 'Basic ') !== 0) {
            return false;
        }

        // Extract and decode credentials
        $encodedCredentials = substr($authHeader, 6);
        $decodedCredentials = base64_decode($encodedCredentials, true);
        
        if ($decodedCredentials === false) {
            return false;
        }

        $parts = explode(':', $decodedCredentials, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$providedTenantId, $providedToken] = $parts;

        // Mirror ServiceInputProcessor's merged input and camelCase/snake_case precedence.
        $storeId = null;
        $params = (array) $this->request->getRequestData();
        $storeCode = $params['storeCode'] ?? $params['store_code'] ?? null;
        if ($storeCode !== null && $storeCode !== '') {
            // Only customer/update accepts a target store; carts always use their route's store.
            if (!preg_match('#(?:^|/)V1/loyalty/customer/update/?$#', (string) $this->request->getPathInfo())
                || (!is_string($storeCode) && !is_int($storeCode))) {
                return false;
            }
            try {
                $storeId = (int) $this->stores->getStore($storeCode)->getId();
            } catch (\Throwable $e) {
                return false;
            }
            if ($storeId <= 0 || !$this->loyaltyHelper->isLoyaltyEngageEnabled($storeId)) {
                return false;
            }
        }

        // Get configured credentials
        $configuredTenantId = $this->loyaltyHelper->getClientId($storeId);
        $configuredToken = $this->loyaltyHelper->getClientSecret($storeId);

        // Validate credentials using timing-safe comparison
        if (empty($configuredTenantId) || empty($configuredToken)) {
            $this->loyaltyHelper->log(
                'critical',
                'AuthenticationPlugin',
                'validateBasicAuth',
                'API credentials not configured'
            );
            return false;
        }

        // Use hash_equals for timing-safe comparison to prevent timing attacks
        $tenantIdValid = hash_equals($configuredTenantId, $providedTenantId);
        $tokenValid = hash_equals($configuredToken, $providedToken);

        return $tenantIdValid && $tokenValid;
    }
}
