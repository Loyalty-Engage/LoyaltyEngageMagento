<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class ReviewObserver implements ObserverInterface
{
    /**
     * @var PublisherInterface
     */
    private $publisher;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param PublisherInterface $publisher
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        PublisherInterface $publisher,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->publisher = $publisher;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
    }

    /**
     * Execute observer - lightweight review capture
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Early exit if module or review export is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isReviewExportEnabled()) {
            return;
        }

        try {
            $review = $observer->getEvent()->getObject();
            
            // Only process approved reviews
            if ($review->getStatusId() != \Magento\Review\Model\Review::STATUS_APPROVED) {
                return;
            }

            // Get customer email (lightweight approach)
            $customerEmail = $this->getCustomerEmail($review);
            if (!$customerEmail) {
                $this->logger->info('[LOYALTY-REVIEW] No customer email found for review ID: ' . $review->getId());
                return;
            }

            // Prepare lightweight review data for queue
            $reviewData = [
                'review_id' => $review->getId(),
                'customer_email' => $customerEmail,
                'product_id' => $review->getEntityPkValue(),
                'timestamp' => time()
            ];

            // Queue the review data (async processing)
            $this->publisher->publish('loyaltyshop.review_event', json_encode($reviewData));
            
            $this->logger->info('[LOYALTY-REVIEW] Review queued for export: ' . $review->getId());

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-REVIEW] Error queuing review: ' . $e->getMessage());
        }
    }

    /**
     * Get customer email from review (lightweight method)
     *
     * @param \Magento\Review\Model\Review $review
     * @return string|null
     */
    private function getCustomerEmail($review): ?string
    {
        // Try to get from customer ID first (most efficient)
        if ($review->getCustomerId()) {
            try {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $customerRepository = $objectManager->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);
                $customer = $customerRepository->getById($review->getCustomerId());
                return $customer->getEmail();
            } catch (\Exception $e) {
                $this->logger->warning('[LOYALTY-REVIEW] Could not load customer: ' . $e->getMessage());
            }
        }

        // Fallback to nickname if it's an email format
        $nickname = $review->getNickname();
        if ($nickname && filter_var($nickname, FILTER_VALIDATE_EMAIL)) {
            return $nickname;
        }

        return null;
    }
}
