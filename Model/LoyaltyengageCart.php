<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Psr\Log\LoggerInterface;


class LoyaltyengageCart
{
    protected const XML_PATH_API_URL = 'loyalty/general/loyalty_api_url';

    protected const XML_PATH_TENANT_ID = 'loyalty/general/tenant_id';

    protected const XML_PATH_BEARER_TOKEN = 'loyalty/general/bearer_token';

    protected const XML_PATH_CART_EXPIRY_TIME = 'loyalty/general/cart_expiry_time';

    protected const XML_PATH_LOGGER = 'loyalty/general/logger_enable';

    protected const XML_PATH_LOYALTY_ORDER_PLACE_RETRIEVE = 'loyalty/general/loyalty_order_place_retrieve';

    /**
     * Constructor
     *
     * @param Curl $curl
     * @param RestRequest $request
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        protected Curl $curl,
        protected RestRequest $request,
        protected ScopeConfigInterface $scopeConfig,
        protected LoggerInterface $logger,
        protected EncryptorInterface $encryptor,
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
     * Get Tenant ID from config (decrypted)
     *
     * @return null|string
     */
    public function getTenantID(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_TENANT_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return null;
        }
        
        // Decrypt the value - stored encrypted in database
        return $this->encryptor->decrypt($value);
    }

    /**
     * Get Bearer Token from config (decrypted)
     *
     * @return string
     */
    public function getBearerToken(): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_BEARER_TOKEN,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return '';
        }
        
        // Decrypt the value - stored encrypted in database
        return $this->encryptor->decrypt($value) ?? '';
    }

    /**
     * Get  config value  for Logger
     *
     * @return null|string
     */
    public function getLoggerStatus(): ?string
    {
        $bearerToken = $this->scopeConfig->getValue(
            self::XML_PATH_LOGGER,
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
        $tenantId = $this->getTenantID();
        $bearerToken = $this->getBearerToken();

        $authString = base64_encode($tenantId . ':' . $bearerToken);
        return $authString;
    }

    /**
     * Validate and sanitize email address
     *
     * @param string $email
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateEmail(string $email): string
    {
        $email = trim($email);
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address provided');
        }
        return $email;
    }

    /**
     * Validate and sanitize SKU
     *
     * @param string $sku
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateSku(string $sku): string
    {
        $sku = trim($sku);
        if (empty($sku) || strlen($sku) > 64) {
            throw new \InvalidArgumentException('Invalid SKU provided');
        }
        // Only allow alphanumeric, dash, underscore
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $sku)) {
            throw new \InvalidArgumentException('SKU contains invalid characters');
        }
        return $sku;
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
        // Validate and sanitize inputs
        $email = $this->validateEmail($email);
        $sku = $this->validateSku($sku);
        
        $apiUrl = $this->getapiUrl();
        // URL encode the email to prevent injection
        $url = $apiUrl . '/api/v1/loyalty/shop/' . rawurlencode($email) . '/cart/add';
        $payload = [
            'sku' => $sku,
            'quantity' => 1
        ];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->post($url, json_encode($payload));

        // Get response body and status code
        $responseCode = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        // Log if logging is enabled in config (mask email for privacy)
        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage Add to Cart Response:', [
                'email' => $this->maskEmail($email),
                'sku' => $sku,
                'quantity' => 1,
                'response_code' => $responseCode,
                'response_body' => $responseBody
            ]);
        }

        return $responseCode;
    }

    /**
     * Mask email for logging (privacy)
     *
     * @param string $email
     * @return string
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }
        $name = $parts[0];
        $domain = $parts[1];
        $maskedName = strlen($name) > 2 
            ? substr($name, 0, 1) . '***' . substr($name, -1) 
            : '***';
        return $maskedName . '@' . $domain;
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
        // Validate inputs
        $email = $this->validateEmail($email);
        $sku = $this->validateSku($sku);
        $quantity = max(1, min($quantity, 100)); // Limit quantity between 1-100
        
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . rawurlencode($email) . '/cart/remove';
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
     * Remove all products from the loyalty cart
     *
     * @param string $email
     * @return int|null
     */
    public function removeAllItem(string $email): ?int
    {
        // Validate email
        $email = $this->validateEmail($email);
        
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . rawurlencode($email) . '/cart';

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
     * Validate order ID
     *
     * @param string $orderId
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validateOrderId(string $orderId): string
    {
        $orderId = trim($orderId);
        if (empty($orderId) || strlen($orderId) > 50) {
            throw new \InvalidArgumentException('Invalid order ID provided');
        }
        // Only allow alphanumeric and common order ID characters
        if (!preg_match('/^[a-zA-Z0-9\-_#]+$/', $orderId)) {
            throw new \InvalidArgumentException('Order ID contains invalid characters');
        }
        return $orderId;
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
        // Validate inputs
        $email = $this->validateEmail($email);
        $orderId = $this->validateOrderId($orderId);
        
        // Validate products array
        if (empty($products) || !is_array($products)) {
            throw new \InvalidArgumentException('Products array is required');
        }
        
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . rawurlencode($email) . '/cart/purchase';
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

    /**
     * Buy a discount code product using loyalty coins
     *
     * @param string $email Customer email
     * @param string $sku SKU of the discount code product
     * @return array|null Response array with discountCode, discountPercentage, etc. or null on failure
     */
    public function buyDiscountCode(string $email, string $sku): ?array
    {
        // Validate inputs
        $email = $this->validateEmail($email);
        $sku = $this->validateSku($sku);
        
        $apiUrl = $this->getapiUrl();
        $url = $apiUrl . '/api/v1/loyalty/shop/' . rawurlencode($email) . '/cart/buy_discount_code';

        $payload = ['sku' => $sku];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Basic ' . $this->basicAuth());
        $this->curl->post($url, json_encode($payload));

        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        // Log with masked email for privacy
        if ($this->getLoggerStatus()) {
            $this->logger->info('LoyaltyEngage Buy Discount Code Response:', [
                'email' => $this->maskEmail($email),
                'sku' => $sku,
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
