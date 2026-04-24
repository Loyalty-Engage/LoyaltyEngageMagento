<?php
namespace LoyaltyEngage\LoyaltyShop\Logger;

use Monolog\Logger as MonoLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Logger extends MonoLogger
{
    public const LOG_PREFIX = '[LOYALTY-SHOP]';
    
    // Component identifiers
    public const COMPONENT_CART_ADD = 'CART-ADD';
    public const COMPONENT_CART = 'CART';
    public const COMPONENT_DETECTION = 'DETECTION';
    public const COMPONENT_API = 'API';
    public const COMPONENT_PLUGIN = 'PLUGIN';
    public const COMPONENT_OBSERVER = 'OBSERVER';
    public const COMPONENT_VIEWMODEL = 'VIEWMODEL';
    public const COMPONENT_QUEUE = 'QUEUE';
    
    // Action types
    public const ACTION_LOYALTY = 'LOYALTY';
    public const ACTION_REGULAR = 'REGULAR';
    public const ACTION_ERROR = 'ERROR';
    public const ACTION_SUCCESS = 'SUCCESS';
    public const ACTION_VALIDATION = 'VALIDATION';
    public const ACTION_ENVIRONMENT = 'ENVIRONMENT';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var bool|null
     */
    private $loggingEnabled = null;

    /**
     * Correct Constructor for Magento Monolog Logger
     *
     * @param string $name
     * @param array $handlers
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        string $name,
        array $handlers,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($name, $handlers);
        $this->scopeConfig = $scopeConfig;
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
    public function customInfo(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        parent::info($formattedMessage, $context);
    }

    /**
     * Log debug message (only if debug is enabled)
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function customDebug(string $component, string $action, string $message, array $context = []): void
    {
        if ($this->isDebugEnabled()) {
            $formattedMessage = $this->formatMessage($component, $action, $message);
            parent::info($formattedMessage, $context);
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
    public function customError(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        parent::error($formattedMessage, $context);
    }

    /**
     * Log critical message
     *
     * @param string $component
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function customCritical(string $component, string $action, string $message, array $context = []): void
    {
        $formattedMessage = $this->formatMessage($component, $action, $message);
        parent::critical($formattedMessage, $context);
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

    /**
     * Mask email address for privacy in logs
     *
     * Example: customer@example.com -> c***r@e***.com
     *
     * @param string $email
     * @return string
     */
    public function maskEmail(string $email): string
    {
        if (empty($email) || $email === 'guest') {
            return $email;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        // Mask the name part
        if (strlen($name) <= 2) {
            $maskedName = str_repeat('*', strlen($name));
        } else {
            $maskedName = substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1);
        }

        // Mask the domain part (keep TLD)
        $domainParts = explode('.', $domain);
        if (count($domainParts) >= 2) {
            $tld = array_pop($domainParts);
            $domainName = implode('.', $domainParts);
            if (strlen($domainName) <= 2) {
                $maskedDomain = str_repeat('*', strlen($domainName)) . '.' . $tld;
            } else {
                $maskedDomain = substr($domainName, 0, 1) . str_repeat('*', strlen($domainName) - 1) . '.' . $tld;
            }
        } else {
            $maskedDomain = '***';
        }

        return $maskedName . '@' . $maskedDomain;
    }

    /**
     * Check if general logging is enabled
     *
     * @return bool
     */
    public function isLoggingEnabled(): bool
    {
        if ($this->loggingEnabled === null) {
            $this->loggingEnabled = $this->scopeConfig->isSetFlag(
                'loyalty/general/logger_enable',
                ScopeInterface::SCOPE_STORE
            );
        }
        return $this->loggingEnabled;
    }
}
