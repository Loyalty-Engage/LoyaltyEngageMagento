<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Membership
{
    public function __construct(
        private Config $config,
        private CustomerRepositoryInterface $customers,
        private CustomerLoyaltyDataProvider $data,
        private EventOutbox $outbox
    ) {}

    public static function hasFields(array $data): bool
    {
        foreach (CustomerLoyaltyDataProvider::ATTRIBUTE_CODES as $field) {
            $value = $data[$field] ?? null;
            if (is_scalar($value) && !is_bool($value) && trim((string) $value) !== '') { return true; }
        }
        return false;
    }

    public function isMember(int $customerId, int $storeId): bool
    {
        if ($customerId < 1 || $storeId < 1) { return false; }
        try { $customer = $this->customers->getById($customerId); }
        catch (NoSuchEntityException) { return false; }
        if (self::hasFields($this->data->getCustomerLoyaltyData($customer, $storeId))) { return true; }
        $event = $this->outbox->find('loyaltyshop.opt_in_event', [
            'store_id' => $storeId, 'identifier' => (string) $customer->getEmail(),
        ]);
        return ($event['status'] ?? '') === 'sent';
    }

    public function allowsWallet(int $customerId, int $storeId): bool
    {
        return $this->config->mode($storeId) !== 'required' || $this->isMember($customerId, $storeId);
    }

    public function isEmailMember(string $email, int $storeId): bool
    {
        $customer = $this->data->getCustomerByEmail($email, (string) $storeId);
        if ($customer && $this->isMember((int) $customer->getId(), $storeId)) { return true; }
        $event = $this->outbox->find('loyaltyshop.opt_in_event', ['store_id' => $storeId, 'identifier' => $email]);
        return ($event['status'] ?? '') === 'sent';
    }
}
