<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

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
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @param PublisherInterface $publisher
     * @param LoyaltyHelper $loyaltyHelper
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        PublisherInterface $publisher,
        LoyaltyHelper $loyaltyHelper,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->publisher = $publisher;
        $this->loyaltyHelper = $loyaltyHelper;
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
        if (
            !$this->loyaltyHelper->isLoyaltyEngageEnabled() ||
            !$this->loyaltyHelper->isReviewExportEnabled()
        ) {
            return;
        }

        try {
            $review = $observer->getEvent()->getObject();
            if (!$review) {
                return;
            }

            // Only approved reviews
            if ($review->getStatusId() != \Magento\Review\Model\Review::STATUS_APPROVED) {
                return;
            }

            $customerEmail = $this->getCustomerEmail($review);
            if (!$customerEmail) {
                $this->loyaltyHelper->log(
                    'info',
                    'LoyaltyShop',
                    'ReviewSkippedNoEmail',
                    'Review skipped due to missing customer email.',
                    [
                        'review_id' => $review->getId()
                    ]
                );
                return;
            }

            $reviewData = [
                'review_id' => $review->getId(),
                'customer_email' => $customerEmail,
                'product_id' => $review->getEntityPkValue(),
                'timestamp' => time()
            ];

            $this->publisher->publish(
                'loyaltyshop.review_event',
                json_encode($reviewData)
            );

            $this->loyaltyHelper->log(
                'info',
                'LoyaltyShop',
                'ReviewEventPublished',
                'Review queued for loyalty export.',
                [
                    'review_id' => $review->getId(),
                    'email' => $customerEmail,
                    'product_id' => $review->getEntityPkValue(),
                    'payload' => $reviewData
                ]
            );

        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'LoyaltyShop',
                'ReviewEventError',
                'Error queuing review.',
                [
                    'error_message' => $e->getMessage()
                ]
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
        if ($review->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($review->getCustomerId());
                return $customer->getEmail();
            } catch (\Exception $e) {
                // fallback
            }
        }

        $nickname = $review->getNickname();
        if ($nickname && filter_var($nickname, FILTER_VALIDATE_EMAIL)) {
            return $nickname;
        }

        return null;
    }
}
