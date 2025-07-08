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
}
