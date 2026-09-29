<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Exception\LocalizedException;
use LoyaltyEngage\LoyaltyShop\Helper\Data;

class ApiClient
{
    protected Curl $curl;
    protected Json $json;
    protected Data $helper;

    protected int $timeout = 15;
    private ?string $idempotencyKey = null;

    public function setIdempotencyKey(?string $key): void
    {
        $this->idempotencyKey = $key;
    }

    public function __construct(
        Curl $curl,
        Json $json,
        Data $helper
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->helper = $helper;
    }

    /**
     * Common setup
     */
    protected function prepare(?int $storeId = null): void
    {
        $this->curl->setHeaders([]);
        $this->curl->setTimeout($this->timeout);
        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, null);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 5);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        $clientId = $this->helper->getClientId($storeId);
        $clientSecret = $this->helper->getClientSecret($storeId);

        if (!$clientId || !$clientSecret) {
            throw new DeferredDeliveryException('Loyalty Engage credentials are not configured for this store.');
        }
        if ($this->idempotencyKey !== null) {
            $this->curl->addHeader('Idempotency-Key', $this->idempotencyKey);
        }

        if ($clientId && $clientSecret) {
            $auth = base64_encode($clientId . ':' . $clientSecret);
            $this->curl->addHeader('Authorization', 'Basic ' . $auth);
        }

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
    }

    /**
     * Handle response
     */
    protected function handleResponse(): array
    {
        $status = $this->curl->getStatus();
        $body = $this->curl->getBody();

        if ($status === 400) {
            try {
                $error = $this->json->unserialize($body);
            } catch (\InvalidArgumentException $e) {
                $error = null;
            }
            $reason = is_array($error) ? ($error['message'] ?? null) : null;
            if (is_string($reason) && ApiRejectionException::isKnownReason($reason)) {
                throw new ApiRejectionException($reason);
            }
        }

        if ($status < 200 || $status > 299) {
            throw new ApiException('Loyalty Engage returned HTTP ' . $status, (int) $status);
        }

        if (!$body) {
            return [];
        }

        try {
            $data = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException('Loyalty Engage returned invalid JSON.', 502, $e);
        }
        if (!is_array($data) || (($data['success'] ?? null) === false)) {
            throw new ApiException('Loyalty Engage rejected the operation or returned an invalid response.', 422);
        }
        if (array_key_exists('acceptedEventCount', $data) && (int) $data['acceptedEventCount'] <= 0) {
            throw new ApiException('Loyalty Engage accepted no events. Reconcile the event before replaying it.', 422);
        }
        return $data;
    }

    /**
     * GET
     */
    public function get(string $url, array $params = [], ?int $storeId = null): array
    {
        $this->prepare($storeId);

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $this->curl->get($url);

        return $this->handleResponse();
    }

    /**
     * POST
     */
    public function post(string $url, array $body = [], ?int $storeId = null): array
    {
        $this->prepare($storeId);

        $this->curl->post($url, $this->json->serialize($body));

        return $this->handleResponse();
    }

    /**
     * PUT
     */
    public function put(string $url, array $body = [], ?int $storeId = null): array
    {
        $this->prepare($storeId);

        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
        $this->curl->post($url, $this->json->serialize($body));

        return $this->handleResponse();
    }

    public function delete(string $url, array $body = [], ?int $storeId = null): array
    {
        $this->prepare($storeId);

        $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');

        if (!empty($body)) {
            $this->curl->post($url, $this->json->serialize($body));
        } else {
            $this->curl->get($url);
        }

        return $this->handleResponse();
    }
}
