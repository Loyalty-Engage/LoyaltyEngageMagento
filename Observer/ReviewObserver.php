<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;

class ReviewObserver implements ObserverInterface
{
    protected $helper;
    protected $logger;
    protected $publisher;
    protected $customerRepository;

    public function __construct(
        Data $helper,
        LoggerInterface $logger,
        PublisherInterface $publisher,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->publisher = $publisher;
        $this->customerRepository = $customerRepository;
    }

    public function execute(Observer $observer)
    {
        if (!$this->helper->isReviewExportEnabled()) {
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review export is disabled - skipping review processing');
            }
            return;
        }

        $review = $observer->getEvent()->getObject();
        if (!$review || !$review instanceof Review) {
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->warning('[LoyaltyShop] Invalid review object received');
            }
            return;
        }

        $reviewId = $review->getId();
        $currentStatus = $review->getStatusId();
        $originalStatus = $review->getOrigData('status_id');

        if ($this->helper->isReviewDebugLoggingEnabled()) {
            $this->logger->info('[LoyaltyShop] Processing review', [
                'review_id' => $reviewId,
                'current_status' => $currentStatus,
                'original_status' => $originalStatus,
                'customer_id' => $review->getCustomerId(),
                'title' => $review->getTitle(),
                'detail_length' => strlen($review->getDetail() ?? '')
            ]);
        }

        // Only process approved reviews
        if ($currentStatus != Review::STATUS_APPROVED) {
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review not approved - skipping', [
                    'review_id' => $reviewId,
                    'status' => $currentStatus
                ]);
            }
            return;
        }

        // Check if this is a new review or status change to approved
        if ($originalStatus === $currentStatus && $originalStatus == Review::STATUS_APPROVED) {
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review already processed - skipping', [
                    'review_id' => $reviewId
                ]);
            }
            return; // Already processed
        }

        // Check minimum character requirement
        $minCharacters = $this->helper->getReviewMinCharacters();
        $reviewDetail = $review->getDetail() ?? '';
        $reviewLength = strlen(trim($reviewDetail));

        if ($minCharacters > 0 && $reviewLength < $minCharacters) {
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review too short - skipping', [
                    'review_id' => $reviewId,
                    'length' => $reviewLength,
                    'min_required' => $minCharacters
                ]);
            }
            return;
        }

        // Get customer email - try multiple methods
        $email = $this->getCustomerEmail($review);

        if (!$email || !$reviewId) {
            $this->logger->warning('[LoyaltyShop] Review event skipped - missing email or review ID', [
                'review_id' => $reviewId,
                'customer_id' => $review->getCustomerId(),
                'email_found' => !empty($email)
            ]);
            return;
        }

        $payload = [[
            'event' => 'Review',
            'identifier' => $email,
            'reviewid' => (string) $reviewId
        ]];

        try {
            $this->publisher->publish('loyaltyshop.review_event', json_encode($payload));
            
            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review payload published to queue successfully', [
                    'review_id' => $reviewId,
                    'email' => $email,
                    'payload' => $payload[0]
                ]);
            } else {
                $this->logger->info('[LoyaltyShop] Review payload published to queue.', $payload[0]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Failed to queue Review event', [
                'review_id' => $reviewId,
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get customer email from review using multiple methods
     *
     * @param Review $review
     * @return string|null
     */
    private function getCustomerEmail(Review $review): ?string
    {
        // Method 1: Direct customer relationship
        if ($review->getCustomer() && $review->getCustomer()->getEmail()) {
            return $review->getCustomer()->getEmail();
        }

        // Method 2: Customer ID lookup
        $customerId = $review->getCustomerId();
        if ($customerId) {
            try {
                $customer = $this->customerRepository->getById($customerId);
                return $customer->getEmail();
            } catch (\Exception $e) {
                if ($this->helper->isReviewDebugLoggingEnabled()) {
                    $this->logger->warning('[LoyaltyShop] Failed to load customer by ID', [
                        'customer_id' => $customerId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // Method 3: Check if email is stored directly in review (for guest reviews)
        // Note: Magento doesn't store guest emails in reviews by default, but some extensions might
        if (method_exists($review, 'getEmail') && $review->getEmail()) {
            return $review->getEmail();
        }

        if ($this->helper->isReviewDebugLoggingEnabled()) {
            $this->logger->warning('[LoyaltyShop] Could not determine customer email for review', [
                'review_id' => $review->getId(),
                'customer_id' => $customerId,
                'has_customer_object' => !empty($review->getCustomer())
            ]);
        }

        return null;
    }
}
