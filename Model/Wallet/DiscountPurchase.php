<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\MutationJournal;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException;
use Magento\Framework\App\ResourceConnection;

class DiscountPurchase
{
    public function __construct(
        private MutationJournal $journal,
        private ApiClient $api,
        private Data $helper,
        private ResourceConnection $resource
    ) {
    }

    /** The caller must reuse the same persisted intent, identity and slot on retry. */
    public function purchase(
        int $storeId,
        int $customerId,
        int $quoteId,
        string $intent,
        int $slot,
        string $identifier,
        string $sku
    ): array {
        if (min($storeId, $customerId, $quoteId, $slot) < 1
            || !preg_match('/\A[a-f0-9]{64}\z/', $intent)
            || !preg_match('/\A[a-f0-9]{64}\z/', $identifier)
            || !Config::isRedemptionSku($sku)) {
            throw new \InvalidArgumentException('Invalid wallet voucher purchase parameters.');
        }
        // The journal must survive a failed checkout or a lost API response.
        if ($this->resource->getConnection()->getTransactionLevel() !== 0) {
            throw new \LogicException('Purchase wallet vouchers outside the Magento order transaction.');
        }
        $key = hash('sha256', "wallet-voucher:$storeId:$customerId:$quoteId:$intent:$slot");
        $code = 'LE-WALLET-' . $key;
        return $this->journal->response($key, $storeId, $customerId, $quoteId, 'wallet_discount', $sku,
            function () use ($storeId, $identifier, $sku, $code, $key): array {
                $base = rtrim((string) $this->helper->getApiUrl($storeId), '/');
                if ($base === '') {
                    throw new DeferredDeliveryException('Loyalty Engage API URL is not configured.');
                }
                $this->api->setIdempotencyKey($key);
                try {
                    $response = $this->api->post(
                        $base . '/api/v1/loyalty/shop/' . $identifier . '/cart/buy_discount_code',
                        ['sku' => $sku, 'createNewDiscountCode' => true, 'discountCode' => $code],
                        $storeId
                    );
                    if (($response['discountCode'] ?? null) !== $code
                        || !is_int($response['spentCoins'] ?? null) || $response['spentCoins'] < 1) {
                        throw new \UnexpectedValueException('Wallet voucher purchase could not be confirmed.');
                    }
                    return ['discountCode' => $code, 'spentCoins' => $response['spentCoins']];
                } finally {
                    $this->api->setIdempotencyKey(null);
                }
            });
    }
}
