<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api\Data;

interface CustomerLoyaltyUpdateResponseInterface
{
    /**
     * Get success status
     *
     * @return bool
     */
    public function getSuccess(): bool;

    /**
     * Set success status
     *
     * @param bool $success
     * @return $this
     */
    public function setSuccess(bool $success): self;

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Set message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message): self;

    /**
     * Get customer ID
     *
     * @return int|null
     */
    public function getCustomerId(): ?int;

    /**
     * Set customer ID
     *
     * @param int|null $customerId
     * @return $this
     */
    public function setCustomerId(?int $customerId): self;

    /**
     * Get updated fields
     *
     * @return string[]
     */
    public function getUpdatedFields(): array;

    /**
     * Set updated fields
     *
     * @param string[] $updatedFields
     * @return $this
     */
    public function setUpdatedFields(array $updatedFields): self;
}
