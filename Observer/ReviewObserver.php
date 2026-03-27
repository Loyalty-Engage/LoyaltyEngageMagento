<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

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
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @param PublisherInterface $publisher
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        PublisherInterface $publisher,
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->publisher = $publisher;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Execute observer - queue approved review for loyalty export
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
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

            // Get customer email via DI (no ObjectManager)
            $customerEmail = $this->getCustomerEmail($review);
            if (!$customerEmail) {
                return;
            }

            // Prepare review data for queue
            $reviewData = [
                'review_id' => $review->getId(),
                'customer_email' => $customerEmail,
                'product_id' => $review->getEntityPkValue(),
                'timestamp' => time()
            ];

            // Queue the review data for async processing
            $this->publisher->publish('loyaltyshop.review_event', json_encode($reviewData));

            if ($this->loyaltyLogger->isDebugEnabled()) {
                $this->loyaltyLogger->debug(
                    LoyaltyLogger::COMPONENT_QUEUE,
                    LoyaltyLogger::ACTION_LOYALTY,
                    sprintf('Review %d queued for %s', $review->getId(), $this->loyaltyLogger->maskEmail($customerEmail))
                );
            }

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                LoyaltyLogger::COMPONENT_OBSERVER,
                LoyaltyLogger::ACTION_ERROR,
                'Error queuing review: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get customer email from review using DI-injected repository
     *
     * @param \Magento\Review\Model\Review $review
     * @return string|null
     */
    private function getCustomerEmail($review): ?string
    {
        // Try to get from customer ID first (most efficient)
        if ($review->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($review->getCustomerId());
                return $customer->getEmail();
            } catch (\Exception $e) {
                // Customer not found, fall through to nickname check
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
