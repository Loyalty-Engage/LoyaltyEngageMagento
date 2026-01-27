<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Data;

use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterface;
use Magento\Framework\DataObject;

class CustomerLoyaltyUpdateResponse extends DataObject implements CustomerLoyaltyUpdateResponseInterface
{
    /**
     * @inheritdoc
     */
    public function getSuccess(): bool
    {
        return (bool) $this->getData('success');
    }

    /**
     * @inheritdoc
     */
    public function setSuccess(bool $success): CustomerLoyaltyUpdateResponseInterface
    {
        return $this->setData('success', $success);
    }

    /**
     * @inheritdoc
     */
    public function getMessage(): string
    {
        return (string) $this->getData('message');
    }

    /**
     * @inheritdoc
     */
    public function setMessage(string $message): CustomerLoyaltyUpdateResponseInterface
    {
        return $this->setData('message', $message);
    }

    /**
     * @inheritdoc
     */
    public function getCustomerId(): ?int
    {
        $customerId = $this->getData('customer_id');
        return $customerId !== null ? (int) $customerId : null;
    }

    /**
     * @inheritdoc
     */
    public function setCustomerId(?int $customerId): CustomerLoyaltyUpdateResponseInterface
    {
        return $this->setData('customer_id', $customerId);
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedFields(): array
    {
        return (array) $this->getData('updated_fields');
    }

    /**
     * @inheritdoc
     */
    public function setUpdatedFields(array $updatedFields): CustomerLoyaltyUpdateResponseInterface
    {
        return $this->setData('updated_fields', $updatedFields);
    }
}
