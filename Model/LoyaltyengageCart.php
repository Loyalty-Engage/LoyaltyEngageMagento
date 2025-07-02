<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Psr\Log\LoggerInterface;


class LoyaltyengageCart
{
    protected const XML_PATH_API_URL = 'loyalty/general/loyalty_api_url';

    protected const XML_PATH_TENANT_ID = 'loyalty/general/tenant_id';

    protected const XML_PATH_BEARER_TOKEN = 'loyalty/general/bearer_token';

    protected const XML_PATH_CART_EXPIRY_TIME = 'loyalty/general/cart_expiry_time';

    protected const XML_PATH_LOGGEER = 'loyalty/general/logger_enable';

    protected const XML_PATH_LOYALTY_ORDER_PLACE_RETRIEVE = 'loyalty/general/loyalty_order_place_retrieve';

    /**
     * Constructor
     *
     * @param Curl $curl
     * @param RestRequest $request
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        protected Curl $curl,
        protected RestRequest $request,
        protected ScopeConfigInterface $scopeConfig,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Get  config value  api url
     *
     * @return null|string
     */
    public function getapiUrl(): ?string
    {
        $apiUrl = $this->scopeConfig->getValue(
            self::XML_PATH_API_URL,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $apiUrl;
    }

    /**
     * Get Tenant ID from config
     *
     * @return null|string
     */
    public function getTenantID(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_TENANT_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get  config value  api url
     *
     * @return string
     */
    public function getBearerToken(): string
    {

        $bearerToken = $this->scopeConfig->getValue(
            self::XML_PATH_BEARER_TOKEN,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $bearerToken;
    }

    /**
     * Get  config value  for Logger
     *
     * @return null|string
     */
    public function getLoggerStatus(): ?string
    {
        $bearerToken = $this->scopeConfig->getValue(
            self::XML_PATH_LOGGEER,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $bearerToken;
    }

    /**
     * BasicAuth function
     *
     * @return string
     */
    private function basicAuth(): string
    {
        $tenantId = $this->getTenantId();
        $bearerToken = $this->getBearerToken();

        $authString = base64_encode($tenantId . ':' . $bearerToken);
        return $authString;
    }

    /**
     * Add a product to the loyalty cart
     *
     * @param string $email
     * @param string $sku
     * @return int
     */
    public function addToCart(string $email, string $sku): int
        {
    $apiUrl = $this->getapiUrl();
    $url = $apiUrl . '/api/v1/loyalty/shop/' . $email . '/cart/add';
    $payload = [
        'sku' => $sku,
        'quantity' => 1
    ];

    $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
    $this->curl->post($url, json_encode($payload));

    // Get response body and status code
    $responseCode = $this->curl->getStatus();
    $responseBody = $this->curl->getBody();

    // Log if logging is enabled in config
    if ($this->getLoggerStatus()) {
        $this->logger->info('LoyaltyEngage Add to Cart Response:', [
            'email' => $email,
            'sku' => $sku,
            'quantity' => 1,
            'response_code' => $responseCode,
            'response_body' => $responseBody
        ]);
    }

    return $responseCode;
    }

    /**
     * Remove a product from the loyalty cart
     *
     * @param string $email
     * @param string $sku
     * @param integer $quantity
     * @return int|null
     */
    public function removeItem(string $email, string $sku, int $quantity): ?int
    {
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . $email . '/cart/remove';
        $data = [
            'sku' => $sku,
            'quantity' => $quantity
        ];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        $this->curl->post($url, json_encode($data));

        $responseCode = $this->curl->getStatus();
        return $responseCode;
    }

    /**
     * Remove  All product
     *
     * @param string $email
     * @return int|null
     */
    public function removeAllItem(string $email): ?int
    {
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . $email . '/cart';

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        $this->curl->post($url, null);

        $responseCode = $this->curl->getStatus();
        return $responseCode;
    }

    /**
     * Get Cart Expiry Time
     *
     * @return string
     */
    public function getexpiryTime(): string
    {
        $expiryTime = $this->scopeConfig->getValue(
            self::XML_PATH_CART_EXPIRY_TIME,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        // Ensure we always return a valid string
        if ($expiryTime === null || $expiryTime === '') {
            return '24'; // Default to 24 hours if not set
        }
        
        // Force conversion to string to prevent type errors
        return (string)$expiryTime;
    }

    /**
     * Get Loyalty Order Retrieve Limit from config
     *
     * @return int
     */
    public function getLoyaltyOrderRetrieveLimit(): int
    {
        $retrieveLimit = $this->scopeConfig->getValue(
            self::XML_PATH_LOYALTY_ORDER_PLACE_RETRIEVE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return (int) $retrieveLimit;
    }

    /**
     * PlaceOrder function
     *
     * @param string $email
     * @param string $orderId
     * @param array $products
     * @return integer|null
     */
    public function placeOrder(string $email, string $orderId, array $products): ?int
    {
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . $email . '/cart/purchase';
        $data = [
            'orderId' => $orderId,
            'products' => $products
        ];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'POST');
        $this->curl->post($url, json_encode($data));

        $responseCode = $this->curl->getStatus();
        return $responseCode;
    }

    public function claimDiscount(string $email, float $discount): ?array
    {
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/discount/' . $email . '/claim';

        $payload = ['discount' => $discount];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->post($url, json_encode($payload));

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage Discount Claim Response:', [
                'email' => $email,
                'discount' => $discount,
                'response_code' => $status,
                'response_body' => $body
            ]);
        }

        if ($status !== 200) {
            return null;
        }

        return json_decode($body, true);
    }

}
