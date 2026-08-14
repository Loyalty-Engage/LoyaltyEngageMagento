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

        $clientId = $this->helper->getClientId($storeId);
        $clientSecret = $this->helper->getClientSecret($storeId);

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

        if ($status < 200 || $status > 299) {
            throw new LocalizedException(
                __('API failed. Status: %1 Response: %2', $status, $body)
            );
        }

        if (!$body) {
            return [];
        }

        try {
            return $this->json->unserialize($body);
        } catch (\Exception $e) {
            return ['raw' => $body];
        }
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
