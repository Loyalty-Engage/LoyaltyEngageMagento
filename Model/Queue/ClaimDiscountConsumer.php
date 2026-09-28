<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;

class ClaimDiscountConsumer extends AbstractConsumer
{
    public const TOPIC = 'loyaltyshop.claim_discount_event';

    public function __construct(Data $helper, private ApiClient $api)
    {
        parent::__construct($helper);
    }

    protected function execute(array $payload): void
    {
        $storeId = (int) $payload['store_id'];
        $url = rtrim((string) $this->helper->getApiUrl($storeId), '/') . '/api/v1/discount/'
            . rawurlencode($payload['identifier']) . '/claim';
        $this->api->post($url, ['discountAmount' => $payload['amount'], 'discountCurrency' => $payload['currency']], $storeId);
    }
}
