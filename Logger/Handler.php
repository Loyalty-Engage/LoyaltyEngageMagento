<?php
namespace LoyaltyEngage\LoyaltyShop\Logger;

use Monolog\Logger;
use Magento\Framework\Logger\Handler\Base as BaseHandler;

class Handler extends BaseHandler
{
    /**
     * Log file path for LoyaltyShop module
     *
     * @var string
     */
    protected $fileName = '/var/log/loyaltyshop.log';

    /**
     * Minimum logging level for this handler
     *
     * @var int
     */
    protected $loggerType = Logger::INFO;
}
