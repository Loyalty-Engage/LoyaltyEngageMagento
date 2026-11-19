<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Framework\Exception\LocalizedException;

class Enroll
{
    public function __construct(
        private Config $config,
        private Membership $membership,
        private EventOutbox $outbox
    ) {}

    public function status(string $email, int $storeId): string
    {
        if ($this->membership->isEmailMember($email, $storeId)) {
            return 'member';
        }
        $event = $this->outbox->find('loyaltyshop.opt_in_event', ['store_id' => $storeId, 'identifier' => $email]);
        return !$event ? 'not_enrolled' : (($event['status'] ?? '') === 'failed' ? 'failed' : 'pending');
    }

    public function execute(string $email, int $storeId, string $source, array $audit = []): string
    {
        if ($storeId < 1 || $this->config->mode($storeId) !== 'required') {
            throw new LocalizedException(__('Loyalty enrollment is not enabled for this store.'));
        }
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('A valid customer email is required for loyalty enrollment.'));
        }
        $status = $this->status($email, $storeId);
        if ($status !== 'not_enrolled') {
            return $status;
        }
        $payload = [
            'event' => 'LoyaltyEngageOptIn',
            'identifier' => $email,
            'store_id' => $storeId,
            'source' => $source,
            'consent_label' => $this->config->label($storeId),
            'consent_at' => gmdate('Y-m-d H:i:s'),
        ];
        $audit = array_intersect_key($audit, array_flip(['consent_label', 'consent_at', 'order_id', 'admin_user_id']));
        $this->outbox->publish('loyaltyshop.opt_in_event', json_encode(array_replace($payload, $audit), JSON_THROW_ON_ERROR));
        return 'pending';
    }
}
