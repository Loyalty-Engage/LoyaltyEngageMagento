<?php
namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use Magento\Framework\HTTP\Client\Curl;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
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
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @param Curl $curl
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        Curl $curl,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->curl = $curl;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Process review export queue message
     * Note: Logging is now minimal for privacy
     *
     * @param string $message
     * @return void
     */
    public function process(string $message): void
    {
        try {
            // Early exit if module or review export is disabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isReviewExportEnabled()) {
                return;
            }

            $reviewData = json_decode($message, true);
            if (!$reviewData || !isset($reviewData['review_id'], $reviewData['customer_email'])) {
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_QUEUE,
                    LoyaltyLogger::ACTION_ERROR,
                    'Invalid review data in queue'
                );
                return;
            }

            $this->exportReviewToLoyaltyEngage($reviewData);

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                'Error processing review: ' . $e->getMessage()
            );
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
            return;
        }

        // Prepare API payload (email is needed for API, not logged)
        $payload = [
            [
                'event' => 'Review',
                'identifier' => $reviewData['customer_email'],
                'reviewid' => (string)$reviewData['review_id']
            ]
        ];

        // Prepare API endpoint
        $endpoint = rtrim($apiUrl, '/') . '/api/v1/events';

        try {
            // Set headers with Basic Authentication
            $tenantId = $this->loyaltyHelper->getClientId();
            $bearerToken = $this->loyaltyHelper->getClientSecret();
            
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ];
            
            if ($tenantId && $bearerToken) {
                $authString = base64_encode($tenantId . ':' . $bearerToken);
                $headers['Authorization'] = 'Basic ' . $authString;
            }

            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(10);

            // Make API call
            $this->curl->post($endpoint, json_encode($payload));
            
            $httpCode = $this->curl->getStatus();

            if ($httpCode < 200 || $httpCode >= 300) {
                // Only log errors
                $this->loyaltyLogger->error(
                    LoyaltyLogger::COMPONENT_API,
                    LoyaltyLogger::ACTION_ERROR,
                    sprintf(
                        'Review export failed - ID: %s, HTTP: %d',
                        $reviewData['review_id'],
                        $httpCode
                    )
                );
                throw new \Exception('API returned HTTP ' . $httpCode);
            }

            // Only log success if debug is enabled
            if ($this->loyaltyLogger->isDebugEnabled()) {
                $this->loyaltyLogger->debug(
                    LoyaltyLogger::COMPONENT_QUEUE,
                    LoyaltyLogger::ACTION_SUCCESS,
                    sprintf(
                        'Review exported - ID: %s, Customer: %s',
                        $reviewData['review_id'],
                        $this->loyaltyLogger->maskEmail($reviewData['customer_email'])
                    )
                );
            }

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('Export failed - Review ID: %s, Error: %s', $reviewData['review_id'], $e->getMessage())
            );
            throw $e;
        }
    }
}
