<?php
namespace LoyaltyEngage\LoyaltyShop\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\ScopeInterface;

class Logger extends AbstractHelper
{
    const LOG_PREFIX = '[LOYALTY-SHOP]';
    
    // Component identifiers
    const COMPONENT_CART_ADD = 'CART-ADD';
    const COMPONENT_CART = 'CART';
    const COMPONENT_DETECTION = 'DETECTION';
    const COMPONENT_API = 'API';
    const COMPONENT_PLUGIN = 'PLUGIN';
    const COMPONENT_OBSERVER = 'OBSERVER';
    const COMPONENT_VIEWMODEL = 'VIEWMODEL';
    const COMPONENT_QUEUE = 'QUEUE';
    
    // Action types
    const ACTION_LOYALTY = 'LOYALTY';
    const ACTION_REGULAR = 'REGULAR';
    const ACTION_ERROR = 'ERROR';
    const ACTION_SUCCESS = 'SUCCESS';
    const ACTION_VALIDATION = 'VALIDATION';
    const ACTION_ENVIRONMENT = 'ENVIRONMENT';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->logger = $logger;
    }

    /**
     * Check if debug logging is enabled
     *
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'loyalty/general/debug_logging',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Log info message with standardized format
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function info(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        $this->logger->info($formattedMessage, $context);
    }

    /**
     * Log debug message (only if debug is enabled)
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function debug(string $component, string $action, string $message, array $context = []): void
    {
        if ($this->isDebugEnabled()) {
            $formattedMessage = $this->formatMessage($component, $action, $message);
            $this->logger->debug($formattedMessage, $context);
        }
    }

    /**
     * Log error message
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function error(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        $this->logger->error($formattedMessage, $context);
    }

    /**
     * Log critical message
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function critical(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        $this->logger->critical($formattedMessage, $context);
    }

    /**
     * Log cart addition event
     *
     * @param string $productType (LOYALTY|REGULAR)
     * @param string $sku
     * @param string $customerEmail
     * @param string $source
     * @param array $additionalData
     */
    public function logCartAddition(string $productType, string $sku, string $customerEmail, string $source, array $additionalData = []): void
    {
        $message = sprintf(
            'Product %s added via %s for %s',
            $sku,
            $source,
            $customerEmail
        );
        
        $context = array_merge([
            'sku' => $sku,
            'customer_email' => $customerEmail,
            'source' => $source,
            'product_type' => $productType
        ], $additionalData);

        $this->info(self::COMPONENT_CART_ADD, $productType, $message, $context);
    }

    /**
     * Log loyalty detection result
     *
     * @param string $sku
     * @param string $productName
     * @param bool $isLoyalty
     * @param string $detectionMethod
     * @param array $detectionData
     */
    public function logLoyaltyDetection(string $sku, string $productName, bool $isLoyalty, string $detectionMethod, array $detectionData = []): void
    {
        $result = $isLoyalty ? 'LOYALTY' : 'REGULAR';
        $message = sprintf(
            'Product "%s" (%s) detected as %s via %s',
            $productName,
            $sku,
            $result,
            $detectionMethod
        );

        $context = array_merge([
            'sku' => $sku,
            'product_name' => $productName,
            'is_loyalty' => $isLoyalty,
            'detection_method' => $detectionMethod
        ], $detectionData);

        $this->info(self::COMPONENT_DETECTION, $result, $message, $context);
    }

    /**
     * Log API interaction
     *
     * @param string $endpoint
     * @param string $method
     * @param int $responseCode
     * @param string $message
     * @param array $requestData
     */
    public function logApiInteraction(string $endpoint, string $method, int $responseCode, string $message, array $requestData = []): void
    {
        $action = ($responseCode >= 200 && $responseCode < 300) ? self::ACTION_SUCCESS : self::ACTION_ERROR;
        
        $logMessage = sprintf(
            '%s %s - %d: %s',
            $method,
            $endpoint,
            $responseCode,
            $message
        );

        $context = [
            'endpoint' => $endpoint,
            'method' => $method,
            'response_code' => $responseCode,
            'request_data' => $requestData
        ];

        $this->info(self::COMPONENT_API, $action, $logMessage, $context);
    }

    /**
     * Log environment context
     *
     * @param array $environmentData
     */
    public function logEnvironmentContext(array $environmentData): void
    {
        $message = sprintf(
            'Environment: Enterprise=%s | B2B=%s | Store=%s',
            $environmentData['is_enterprise'] ? 'Yes' : 'No',
            $environmentData['is_b2b'] ? 'Yes' : 'No',
            $environmentData['store_code'] ?? 'unknown'
        );

        $this->info(self::COMPONENT_DETECTION, self::ACTION_ENVIRONMENT, $message, $environmentData);
    }

    /**
     * Format log message with prefix and component/action
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @return string
     */
    private function formatMessage(string $component, string $action, string $message): string
    {
        return sprintf('%s [%s] [%s] - %s', self::LOG_PREFIX, $component, $action, $message);
    }
}
