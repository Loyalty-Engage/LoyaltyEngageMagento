<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\WebApi;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

/**
 * Plugin to authenticate LoyaltyEngage API requests using Basic Auth
 * 
 * This plugin validates that incoming requests to LoyaltyEngage endpoints
 * contain valid Basic Auth credentials matching the configured tenant_id and bearer_token.
 */
class AuthenticationPlugin
{
    /**
     * @var RestRequest
     */
    private $request;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RestRequest $request
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        RestRequest $request,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
    }

    /**
     * Validate Basic Auth credentials before processing the request
     *
     * @param \Magento\Webapi\Controller\Rest $subject
     * @param RequestInterface $request
     * @return array
     * @throws AuthorizationException
     */
    public function beforeDispatch(\Magento\Webapi\Controller\Rest $subject, RequestInterface $request): array
    {
        $pathInfo = $request->getPathInfo();
        
        // Only validate requests to LoyaltyEngage endpoints
        if (!$this->isLoyaltyEngageEndpoint($pathInfo)) {
            return [$request];
        }

        // Check if module is enabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            throw new AuthorizationException(__('LoyaltyEngage module is disabled.'));
        }

        // Validate Basic Auth credentials
        if (!$this->validateBasicAuth()) {
            $this->logger->warning('[LoyaltyEngage] Unauthorized API request attempt', [
                'path' => $pathInfo,
                'ip' => $request->getClientIp()
            ]);
            throw new AuthorizationException(__('Invalid or missing authentication credentials.'));
        }

        return [$request];
    }

    /**
     * Check if the request is for a LoyaltyEngage endpoint
     *
     * @param string|null $pathInfo
     * @return bool
     */
    private function isLoyaltyEngageEndpoint(?string $pathInfo): bool
    {
        if (empty($pathInfo)) {
            return false;
        }

        // Match LoyaltyEngage API endpoints
        $loyaltyEndpoints = [
            '/V1/loyalty/',
            '/rest/V1/loyalty/',
            '/rest/default/V1/loyalty/',
            '/rest/all/V1/loyalty/'
        ];

        foreach ($loyaltyEndpoints as $endpoint) {
            if (stripos($pathInfo, $endpoint) !== false) {
                return true;
            }
        }

        return false;
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
        $decodedCredentials = base64_decode($encodedCredentials);
        
        if ($decodedCredentials === false) {
            return false;
        }

        $parts = explode(':', $decodedCredentials, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$providedTenantId, $providedToken] = $parts;

        // Get configured credentials
        $configuredTenantId = $this->loyaltyHelper->getClientId();
        $configuredToken = $this->loyaltyHelper->getClientSecret();

        // Validate credentials using timing-safe comparison
        if (empty($configuredTenantId) || empty($configuredToken)) {
            $this->logger->error('[LoyaltyEngage] API credentials not configured');
            return false;
        }

        // Use hash_equals for timing-safe comparison to prevent timing attacks
        $tenantIdValid = hash_equals($configuredTenantId, $providedTenantId);
        $tokenValid = hash_equals($configuredToken, $providedToken);

        return $tenantIdValid && $tokenValid;
    }
}
