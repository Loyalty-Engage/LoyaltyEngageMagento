<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Service;

class ApiException extends \RuntimeException
{
    public function isRetryable(): bool
    {
        return in_array($this->getCode(), [0, 408, 425, 429], true) || $this->getCode() >= 500;
    }
}
