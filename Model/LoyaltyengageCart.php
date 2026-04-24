<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Logger\Logger;

/**
 * Class LoyaltyengageCart
 *
 * Handles all Loyalty Cart related operations:
 * - Add to cart
 * - Remove item
 * - Remove all items
 * - Place order
 * - Buy discount code
 *
 * This class acts as a business layer and delegates API communication
 * to ApiClient service.
 */
class LoyaltyengageCart
{
    /**
     * @var ApiClient
     */
    protected ApiClient $apiClient;
    
    /**
     * @var LoyaltyHelper
     */
    protected LoyaltyHelper $helper;

    /**
     * Constructor
     *
     * @param ApiClient $apiClient API client service for external calls
     * @param LoyaltyHelper $helper Helper for config and utilities
     */
    public function __construct(
        ApiClient $apiClient,
        LoyaltyHelper $helper
    ) {
        $this->apiClient   = $apiClient;
        $this->helper      = $helper;
    }

    /**
     * Add product to loyalty cart
     *
     * @param string $email Customer email
     * @param string $sku Product SKU
     * @return int HTTP status code
     */
    public function addToCart(string $email, string $sku): int
    {
        $url = $this->helper->getApiUrl() . '/api/v1/loyalty/shop/'.$email.'/cart/add';

        try {
            $this->apiClient->post($url, [
                'sku'      => $sku,
                'quantity' => 1
            ]);

            $this->logSuccess('AddToCart Success', [
                'email' => $email,
                'sku'   => $sku
            ]);

            return LoyaltyHelper::HTTP_OK;
        } catch (\Exception $e) {
            $this->logError('AddToCart Error', [
                'email' => $email,
                'sku'   => $sku,
                'error' => $e->getMessage()
            ]);
            return LoyaltyHelper::HTTP_BAD_REQUEST;
        }
    }

    /**
     * Remove item from loyalty cart
     *
     * @param string $email Customer email
     * @param string $sku Product SKU
     * @param int $quantity Quantity to remove
     * @return int|null HTTP status code
     */
    public function removeItem(string $email, string $sku, int $quantity): ?int
    {
        $url = $this->helper->getApiUrl() . '/api/v1/loyalty/shop/'.$email.'/cart/remove';

        try {
            $this->apiClient->delete($url, [
                'sku'      => $sku,
                'quantity' => $quantity
            ]);

            $this->logSuccess('RemoveItem Success', [
                'email' => $email,
                'sku'   => $sku,
                'quantity' => $quantity
            ]);

            return LoyaltyHelper::HTTP_OK;
        } catch (\Exception $e) {
            $this->logError('RemoveItem Error', [
                'email' => $email,
                'sku'   => $sku,
                'error' => $e->getMessage()
            ]);
            return LoyaltyHelper::HTTP_BAD_REQUEST;
        }
    }

    /**
     * Remove all items from loyalty cart
     *
     * @param string $email Customer email
     * @return int|null HTTP status code
     */
    public function removeAllItem(string $email): ?int
    {
        $url = $this->helper->getApiUrl() . '/api/v1/loyalty/shop/'.$email.'/cart';

        try {
            $this->apiClient->delete($url);
            
            $this->logSuccess('RemoveAllItem Success', [
                'email' => $email
            ]);

            return LoyaltyHelper::HTTP_OK;
        } catch (\Exception $e) {
            $this->logError('RemoveAllItem Error', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return LoyaltyHelper::HTTP_BAD_REQUEST;
        }
    }

    /**
     * Place order using loyalty cart
     *
     * @param string $email Customer email
     * @param string $orderId Magento Order ID
     * @param array $products Product list
     * @return int|null HTTP status code
     */
    public function placeOrder(string $email, string $orderId, array $products): ?int
    {
        $url = $this->helper->getApiUrl() . '/api/v1/loyalty/shop/'.$email.'/cart/purchase';
        try {
            $this->apiClient->post($url, [
                'orderId' => $orderId,
                'products' => $products
            ]);

            $this->logSuccess('PlaceOrder Success', [
                'email'   => $email,
                'orderId' => $orderId,
                'products'=> $products
            ]);

            return LoyaltyHelper::HTTP_OK;

        } catch (\Exception $e) {
            $this->logError('PlaceOrder Error', [
                'email'   => $email,
                'orderId' => $orderId,
                'error'   => $e->getMessage()
            ]);

            return LoyaltyHelper::HTTP_BAD_REQUEST;
        }
    }

    /**
     * Buy discount code using loyalty points
     *
     * @param string $email Customer email
     * @param string $sku Discount SKU
     * @return array|null API response or null on failure
     */
    public function buyDiscountCode(string $email, string $sku): ?array
    {
        $url = $this->helper->getApiUrl() . '/api/v1/loyalty/shop/'.$email.'/cart/buy_discount_code';

        try {
            $response = $this->apiClient->post($url, [
                'sku' => $sku
            ]);

            $this->logSuccess('BuyDiscountCode Success', [
                'email' => $email,
                'sku'   => $sku,
                'response' => $response
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logError('BuyDiscountCode Error', [
                'email' => $email,
                'sku'   => $sku,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Claim a discount code using loyalty points
     *
     * @param string $identifier Discount identifier (e.g. the discount code)
     * @param float|int $discountAmount Discount amount applied
     * @param string $discountCurrency Currency code
     * @return array|null API response or null on failure
     */
    public function claimDiscount(string $email, float $amount, string $currency = 'EUR'): ?array
    {
        $url = $this->helper->getApiUrl() . '/api/v1/discount/'.$email.'/claim';

        $this->helper->log(
            'info',
            'API',
            'CLAIM_DISCOUNT_REQUEST',
            'Calling Claim Discount API',
            [
                'url'        => $url,
                'identifier' => $email,
                'amount'     => $amount,
                'currency'   => $currency
            ]
        );

        try {
            $response = $this->apiClient->post($url, [
                'discountAmount'   => $amount,
                'discountCurrency' => $currency
            ]);

            $this->helper->log(
                'debug',
                'API',
                'CLAIM_DISCOUNT_SUCCESS',
                'Claim Discount API Success',
                [
                    'identifier' => $email,
                    'response'   => $response
                ]
            );

            return $response;

        } catch (\Exception $e) {

            $this->helper->log(
                'error',
                'API',
                'CLAIM_DISCOUNT_ERROR',
                'Claim Discount API Failed',
                [
                    'identifier' => $email,
                    'error'      => $e->getMessage()
                ]
            );

            return null;
        }
    }

    /**
     * Log error messages if logging is enabled
     *
     * @param string $message Log message
     * @param array $context Additional data
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        $this->helper->log(
            'error',
            Logger::COMPONENT_API,
            Logger::ACTION_ERROR,
            $message,
            $context
        );
    }

    /**
     * Log success messages
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    private function logSuccess(string $message, array $context = []): void
    {
        $this->helper->log(
            'debug',
            Logger::COMPONENT_API,
            Logger::ACTION_SUCCESS,
            $message,
            $context
        );
    }
}
