<?php
namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class ReviewConsumer
{
    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Curl $curl
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
    }

    /**
     * Process review export queue message
     *
     * @param string $message
     * @return void
     */
    public function process(string $message): void
    {
        try {
            // Early exit if module or review export is disabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isReviewExportEnabled()) {
                $this->logger->info('[LOYALTY-REVIEW-CONSUMER] Review export disabled, skipping message');
                return;
            }

            $reviewData = json_decode($message, true);
            if (!$reviewData || !isset($reviewData['review_id'], $reviewData['customer_email'])) {
                $this->logger->error('[LOYALTY-REVIEW-CONSUMER] Invalid review data: ' . $message);
                return;
            }

            $this->exportReviewToLoyaltyEngage($reviewData);

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-REVIEW-CONSUMER] Error processing review: ' . $e->getMessage());
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Export review to LoyaltyEngage API
     *
     * @param array $reviewData
     * @return void
     */
    private function exportReviewToLoyaltyEngage(array $reviewData): void
    {
        $apiUrl = $this->loyaltyHelper->getApiUrl();
        if (!$apiUrl) {
            $this->logger->error('[LOYALTY-REVIEW-CONSUMER] API URL not configured');
            return;
        }

        // Prepare API payload
        $payload = [
            'event' => 'Review',
            'identifier' => $reviewData['customer_email'],
            'reviewid' => (string)$reviewData['review_id']
        ];

        // Prepare API endpoint
        $endpoint = rtrim($apiUrl, '/') . '/api/v1/events';

        try {
            // Set headers with Basic Authentication (same as working cart code)
            $tenantId = $this->loyaltyHelper->getClientId(); // Actually tenant_id
            $bearerToken = $this->loyaltyHelper->getClientSecret(); // Actually bearer_token
            
            if ($tenantId && $bearerToken) {
                $authString = base64_encode($tenantId . ':' . $bearerToken);
                $this->curl->setHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . $authString
                ]);
            } else {
                $this->curl->setHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);
            }

            // Set timeout for performance
            $this->curl->setTimeout(10);

            // Make API call
            $this->curl->post($endpoint, json_encode($payload));
            
            $httpCode = $this->curl->getStatus();
            $response = $this->curl->getBody();

            // Enhanced logging - Log full API request/response details
            $this->logger->info(sprintf(
                '[LOYALTY-REVIEW-CONSUMER] API Request - URL: %s, Payload: %s, HTTP Code: %d',
                $endpoint,
                json_encode($payload),
                $httpCode
            ));

            $this->logger->info(sprintf(
                '[LOYALTY-REVIEW-CONSUMER] API Response - Review ID: %s, Response: %s',
                $reviewData['review_id'],
                $response
            ));

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->logger->info(sprintf(
                    '[LOYALTY-REVIEW-CONSUMER] Review exported successfully - ID: %s, Customer: %s, HTTP: %d',
                    $reviewData['review_id'],
                    $reviewData['customer_email'],
                    $httpCode
                ));
            } else {
                $this->logger->error(sprintf(
                    '[LOYALTY-REVIEW-CONSUMER] API error - HTTP %d: %s - Review ID: %s',
                    $httpCode,
                    $response,
                    $reviewData['review_id']
                ));
                throw new \Exception('API returned HTTP ' . $httpCode);
            }

        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[LOYALTY-REVIEW-CONSUMER] Export failed - Review ID: %s, Error: %s',
                $reviewData['review_id'],
                $e->getMessage()
            ));
            throw $e;
        }
    }
}
