<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\SerializerInterface; 
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Encryption\EncryptorInterface;


class Data extends AbstractHelper
{
    private const XML_PATH_EXPORT = 'loyalty/export/';
    private const XML_PATH_GENERAL = 'loyalty/general/';
    private const XML_PATH_SHIPPING = 'loyalty/shipping/';

    // HTTP Status Constants
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_NOT_FOUND = 404;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var CustomerSession
     */
    public $customerSession;

    /**
     * @var LoyaltyLogger
     */
    public $loyaltyLogger;

    /**
     * @var CustomerRepositoryInterface
     */
    public $customerRepository;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * Data constructor
     *
     * @param \Magento\Framework\App\Helper\Context $context
     * @param SerializerInterface $serializer
     * @param CustomerSession $customerSession
     * @param LoyaltyLogger $loyaltyLogger
     * @param CustomerRepositoryInterface $customerRepository
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        SerializerInterface $serializer,
        CustomerSession $customerSession,
        LoyaltyLogger $loyaltyLogger,
        CustomerRepositoryInterface $customerRepository,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->serializer = $serializer;
        $this->customerSession = $customerSession;
        $this->loyaltyLogger = $loyaltyLogger;
        $this->customerRepository = $customerRepository;
        $this->encryptor = $encryptor;
    }

    /**
     * Check if LoyaltyEngageEnabled is enabled
     *
     * @return bool
     */
    public function isLoyaltyEngageEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'module_enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Return Export is enabled
     *
     * @return bool
     */
    public function isReturnExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'return_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Purchase Export is enabled
     *
     * @return bool
     */
    public function isPurchaseExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'purchase_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Client ID (Tenant ID) from config
     * Note: This value is stored encrypted in the database
     *
     * @return string|null
     */
    public function getClientId(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'tenant_id',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return null;
        }
        
        // Decrypt the value - Magento's Encrypted backend stores values encrypted
        return $this->encryptor->decrypt($value);
    }

    /**
     * Get Client Secret (Bearer Token) from config
     * Note: This value is stored encrypted in the database
     *
     * @return string|null
     */
    public function getClientSecret(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'bearer_token',
            ScopeInterface::SCOPE_STORE
        );
        
        if (empty($value)) {
            return null;
        }
        
        // Decrypt the value - Magento's Encrypted backend stores values encrypted
        return $this->encryptor->decrypt($value);
    }

    /**
     * Get API URL from config
     *
     * @return string|null
     */
    public function getApiUrl(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'loyalty_api_url',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Review Export is enabled
     *
     * @return bool
     */
    public function isReviewExportEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'review_event',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if Free Shipping for Loyalty Tiers is enabled
     *
     * @return bool
     */
    public function isFreeShippingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHIPPING . 'free_shipping_enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Free Shipping Tiers (semicolon-separated)
     *
     * @return string|null
     */
    public function getFreeShippingTiers(): ?string
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_SHIPPING . 'free_shipping_tiers',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Free Shipping Tiers as array
     *
     * @return array
     */
    public function getFreeShippingTiersArray(): array
    {
        $tiers = $this->getFreeShippingTiers();
        if (empty($tiers)) {
            return [];
        }
        
        return array_map('trim', explode(';', $tiers));
    }

    /**
     * Get Tier Cache Duration in seconds
     *
     * @return int
     */
    public function getTierCacheDuration(): int
    {
        $duration = $this->scopeConfig->getValue(
            self::XML_PATH_SHIPPING . 'tier_cache_duration',
            ScopeInterface::SCOPE_STORE
        );
        
        return $duration ? (int)$duration : 600; // Default 10 minutes
    }

    /**
     * Get Queue Processing Frequency (cron schedule)
     *
     * @return string
     */
    public function getQueueProcessingFrequency(): string
    {
        $frequency = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'queue_processing_frequency',
            ScopeInterface::SCOPE_STORE
        );
        
        return $frequency ?: '*/5 * * * *'; // Default: Every 5 minutes
    }

    /**
     * Get Minimum Order Value for Loyalty Products
     *
     * @return float
     */
    public function getMinimumOrderValueForLoyalty(): float
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value',
            ScopeInterface::SCOPE_STORE
        );
        
        return $value ? (float)$value : 0.0;
    }

    /**
     * Check if minimum order value restriction is enabled
     *
     * @return bool
     */
    public function isMinimumOrderValueEnabled(): bool
    {
        return $this->getMinimumOrderValueForLoyalty() > 0;
    }

    /**
     * Get Minimum Order Value Error Message (with placeholders)
     *
     * @return string
     */
    public function getMinimumOrderValueMessage(): string
    {
        $message = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_message',
            ScopeInterface::SCOPE_STORE
        );
        
        return $message ?: 'Your cart subtotal must be at least €{{minimum}} to add loyalty products. Current subtotal: €{{current}}';
    }

    /**
     * Get formatted Minimum Order Value Error Message with actual values
     *
     * @param float $minimumValue
     * @param float $currentValue
     * @return string
     */
    public function getFormattedMinimumOrderValueMessage(float $minimumValue, float $currentValue): string
    {
        $message = $this->getMinimumOrderValueMessage();
        
        return str_replace(
            ['{{minimum}}', '{{current}}'],
            [number_format($minimumValue, 2), number_format($currentValue, 2)],
            $message
        );
    }

    /**
     * Get Minimum Order Value Message Bar Color
     *
     * @return string
     */
    public function getMinimumOrderValueBarColor(): string
    {
        $color = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_bar_color',
            ScopeInterface::SCOPE_STORE
        );
        
        return $color ?: '#e74c3c';
    }

    /**
     * Get Minimum Order Value Message Text Color
     *
     * @return string
     */
    public function getMinimumOrderValueTextColor(): string
    {
        $color = $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'minimum_order_value_text_color',
            ScopeInterface::SCOPE_STORE
        );
        
        return $color ?: '#ffffff';
    }

    /**
     * Hash customer email using SHA256
     *
     * @param string $email
     * @return string
     */
    public function hashEmail(string $email): string
    {
        $normalizedEmail = strtolower(trim($email, " \t\n\r\0\x0B"));
        return hash('sha256', $normalizedEmail);
    }

    /**
     * Check if custom logger is enabled
     *
     * @return bool
     */
    public function isLoggerEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'logger_enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get loyalty order retrieve retry limit
     *
     * @return int
     */
    public function getLoyaltyOrderRetrieveLimit(): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'loyalty_order_place_retrieve',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Build error API response with HTTP status code
     *
     * @param mixed $response
     * @param string $message
     * @param string $errorType
     * @param int $statusCode
     * @return mixed
     */
    public function errorResponse(
        $response, 
        string $message, 
        string $errorType = 'error',
        int $statusCode = self::HTTP_BAD_REQUEST
    ) {
        $statusCode = (string)$statusCode;
        return $response
            ->setSuccess(false)
            ->setMessage($message)
            ->setErrorType($errorType . '_' . $statusCode);
    }

    /**
     * Build success API response
     *
     * @param mixed $response
     * @param string $message
     * @return mixed
     */
    public function successResponse($response, string $message)
    {
        return $response
            ->setSuccess(true)
            ->setMessage($message);
    }

    /**
     * Get customer data by ID with caching
     *
     * @param int $customerId
     * @return array|null ['customer' => CustomerInterface, 'email' => string, 'hashed_email' => string]
     */
    public function getCustomerDataById(int $customerId): ?array
    {
        if (!$customerId) {
            return null;
        }

        static $customerCache = [];

        if (isset($customerCache[$customerId])) {
            return $customerCache[$customerId];
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            
            if (!$customer || !$customer->getId()) {
                return null;
            }

            $email = $customer->getEmail();
            if (!$email) {
                return null;
            }

            $customerData = [
                'customer' => $customer,
                'email' => $email,
                'hashed_email' => $this->hashEmail($email)
            ];

            $customerCache[$customerId] = $customerData;
            return $customerData;

        } catch (\Exception $e) {
            $this->log(
                'error',
                'Customer_Data',
                'Error',
                'Failed to retrieve customer data',
                [
                    'customer_id' => $customerId,
                    'error' => $e->getMessage()
                ]
            );
            return null;
        }
    }

    /**
     * Get customer session
     */
    public function getCustomerSession()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        return $this->customerSession->getCustomer();
    }

    /**
     * Simplified logging method with switch case
     *
     * @param string $level (debug|info|error|critical)
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log(string $level, string $component, string $action, string $message, array $context = []): void
    {
        switch (strtolower($level)) {
            case 'debug':
                $this->loyaltyLogger->customDebug($component, $action, $message, $context);
                break;
            case 'info':
                $this->loyaltyLogger->customInfo($component, $action, $message, $context);
                break;
            case 'error':
                $this->loyaltyLogger->customError($component, $action, $message, $context);
                break;
            case 'critical':
                $this->loyaltyLogger->customCritical($component, $action, $message, $context);
                break;
            default:
                $this->loyaltyLogger->customInfo($component, $action, $message, $context);
                break;
        }
    }

    /**
     * Mask email for logging
     *
     * @param string $email
     * @return string
     */
    public function logMaskedEmail($email): string
    {
        return $this->loyaltyLogger->maskEmail($email);
    }

    /**
     * Check if quote item is a loyalty product
     *
     * @param QuoteItem $item
     * @param bool $strict If true, only returns true for confirmed loyalty products
     * @return bool
     */
    public function isLoyaltyProduct(QuoteItem $item, bool $strict = false): bool
    {
        // Method 1: Check for loyalty_locked_qty option
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() == '1') {
            return true;
        }

        // Method 2: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options
        $additionalOptions = $item->getOptionByCode('additional_options');
        if ($additionalOptions) {
            try {
                $value = $this->serializer->unserialize($additionalOptions->getValue());
                if (is_array($value)) {
                    foreach ($value as $option) {
                        if (
                            isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                            isset($option['value']) && $option['value'] === '1'
                        ) {
                            return true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Handle unserialize errors - not a loyalty product
                $this->loyaltyLogger->error('Error unserializing additional_options: ' . $e->getMessage());
            }
        }

        // Method 4: Check product data (universal fallback)
        if (!$strict) {
            $product = $item->getProduct();
            if ($product && $product->getData('loyalty_locked_qty')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get masked customer email for privacy
     *
     * @return string
     */
    public function getMaskedCustomerEmail(): string
    {
        return $this->getCustomerEmail() ? $this->logMaskedEmail($this->getCustomerEmail()) : 'guest';
    }

    /**
     * Get current customer email (lightweight method)
     *
     * @return string|null
     */
    public function getCustomerEmail(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        return $this->customerSession->getCustomer()->getEmail();
    }
}
