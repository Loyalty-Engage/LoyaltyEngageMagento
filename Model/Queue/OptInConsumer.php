<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

class OptInConsumer extends \LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer
{
    public const TOPIC = 'loyaltyshop.opt_in_event';

    public function __construct(\LoyaltyEngage\LoyaltyShop\Helper\Data $helper, private \LoyaltyEngage\LoyaltyShop\Service\ApiClient $api)
    { parent::__construct($helper); }

    protected function execute(array $payload): void
    {
        if (($payload['event'] ?? '') !== 'LoyaltyEngageOptIn'
            || !filter_var($payload['identifier'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid loyalty opt-in event.');
        }
        $storeId = (int) $payload['store_id'];
        $this->api->post(rtrim((string) $this->helper->getApiUrl($storeId), '/') . '/api/v1/events', [[
            'event' => 'LoyaltyEngageOptIn', 'identifier' => $payload['identifier'],
        ]], $storeId);
    }
}
