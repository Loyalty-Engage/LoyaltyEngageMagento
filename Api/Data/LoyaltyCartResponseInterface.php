<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api\Data;

interface LoyaltyCartResponseInterface
{
    /**
     * Set the success status of the response.
     *
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success);

    /**
     * Set the message of the response.
     *
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message);

    /**
     * Get the success status of the response.
     *
     * @return bool
     */
    public function getSuccess(): bool;

    /**
     * Get the message of the response.
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Set the bar color for error messages.
     *
     * @param string|null $color
     * @return $this
     */
    public function setBarColor(?string $color);

    /**
     * Get the bar color for error messages.
     *
     * @return string|null
     */
    public function getBarColor(): ?string;

    /**
     * Set the text color for error messages.
     *
     * @param string|null $color
     * @return $this
     */
    public function setTextColor(?string $color);

    /**
     * Get the text color for error messages.
     *
     * @return string|null
     */
    public function getTextColor(): ?string;

    /**
     * Set the error type.
     *
     * @param string|null $errorType
     * @return $this
     */
    public function setErrorType(?string $errorType);

    /**
     * Get the error type.
     *
     * @return string|null
     */
    public function getErrorType(): ?string;
}
