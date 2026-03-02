<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

class LoyaltyCartResponse implements LoyaltyCartResponseInterface
{
    /**
     * @var bool
     */
    protected $success;
    
    /**
     * @var string
     */
    protected $message;

    /**
     * @var string|null
     */
    protected $barColor;

    /**
     * @var string|null
     */
    protected $textColor;

    /**
     * @var string|null
     */
    protected $errorType;

    /**
     * Set success status
     *
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success)
    {
        $this->success = $success;
        return $this;
    }

    /**
     * Set message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message)
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Get success status
     *
     * @return bool
     */
    public function getSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set bar color for error messages
     *
     * @param string|null $color
     * @return $this
     */
    public function setBarColor(?string $color)
    {
        $this->barColor = $color;
        return $this;
    }

    /**
     * Get bar color for error messages
     *
     * @return string|null
     */
    public function getBarColor(): ?string
    {
        return $this->barColor;
    }

    /**
     * Set text color for error messages
     *
     * @param string|null $color
     * @return $this
     */
    public function setTextColor(?string $color)
    {
        $this->textColor = $color;
        return $this;
    }

    /**
     * Get text color for error messages
     *
     * @return string|null
     */
    public function getTextColor(): ?string
    {
        return $this->textColor;
    }

    /**
     * Set error type
     *
     * @param string|null $errorType
     * @return $this
     */
    public function setErrorType(?string $errorType)
    {
        $this->errorType = $errorType;
        return $this;
    }

    /**
     * Get error type
     *
     * @return string|null
     */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }
}
