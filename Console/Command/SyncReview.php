<?php

namespace LoyaltyEngage\LoyaltyShop\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Review\Model\Review;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

class SyncReview extends Command
{
    const REVIEW_ID_ARGUMENT = 'review_id';

    protected $reviewFactory;
    protected $helper;
    protected $publisher;
    protected $customerRepository;
    protected $logger;

    public function __construct(
        ReviewFactory $reviewFactory,
        Data $helper,
        PublisherInterface $publisher,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->reviewFactory = $reviewFactory;
        $this->helper = $helper;
        $this->publisher = $publisher;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('loyaltyshop:sync:review')
            ->setDescription('Manually sync a specific review to LoyaltyEngage')
            ->addArgument(
                self::REVIEW_ID_ARGUMENT,
                InputArgument::REQUIRED,
                'Review ID to sync'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $reviewId = $input->getArgument(self::REVIEW_ID_ARGUMENT);
        
        if (!$this->helper->isReviewExportEnabled()) {
            $output->writeln('<error>Review export is disabled in configuration</error>');
            return Command::FAILURE;
        }

        $review = $this->reviewFactory->create()->load($reviewId);
        
        if (!$review->getId()) {
            $output->writeln("<error>Review with ID {$reviewId} not found</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Processing review ID: {$reviewId}</info>");
        $output->writeln("Title: " . $review->getTitle());
        $output->writeln("Status: " . $this->getStatusText($review->getStatusId()));
        $output->writeln("Customer ID: " . ($review->getCustomerId() ?: 'N/A'));
        $output->writeln("Detail Length: " . strlen($review->getDetail() ?? ''));

        // Check if approved
        if ($review->getStatusId() != Review::STATUS_APPROVED) {
            $output->writeln('<error>Review is not approved - cannot sync</error>');
            return Command::FAILURE;
        }

        // Check minimum character requirement
        $minCharacters = $this->helper->getReviewMinCharacters();
        $reviewDetail = $review->getDetail() ?? '';
        $reviewLength = strlen(trim($reviewDetail));

        if ($minCharacters > 0 && $reviewLength < $minCharacters) {
            $output->writeln("<error>Review too short ({$reviewLength} chars, minimum {$minCharacters} required)</error>");
            return Command::FAILURE;
        }

        // Get customer email
        $email = $this->getCustomerEmail($review, $output);
        
        if (!$email) {
            $output->writeln('<error>Could not determine customer email</error>');
            return Command::FAILURE;
        }

        $output->writeln("Customer Email: {$email}");

        $payload = [[
            'event' => 'Review',
            'identifier' => $email,
            'reviewid' => (string) $reviewId
        ]];

        try {
            $this->publisher->publish('loyaltyshop.review_event', json_encode($payload));
            $output->writeln('<info>Review successfully queued for sync to LoyaltyEngage</info>');
            $output->writeln('Payload: ' . json_encode($payload[0], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to queue review: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getCustomerEmail(Review $review, OutputInterface $output): ?string
    {
        // Method 1: Direct customer relationship
        if ($review->getCustomer() && $review->getCustomer()->getEmail()) {
            $output->writeln('Email found via direct customer relationship');
            return $review->getCustomer()->getEmail();
        }

        // Method 2: Customer ID lookup
        $customerId = $review->getCustomerId();
        if ($customerId) {
            try {
                $customer = $this->customerRepository->getById($customerId);
                $output->writeln('Email found via customer ID lookup');
                return $customer->getEmail();
            } catch (\Exception $e) {
                $output->writeln('<comment>Failed to load customer by ID: ' . $e->getMessage() . '</comment>');
            }
        }

        // Method 3: Check if email is stored directly in review
        if (method_exists($review, 'getEmail') && $review->getEmail()) {
            $output->writeln('Email found directly in review');
            return $review->getEmail();
        }

        return null;
    }

    private function getStatusText(int $statusId): string
    {
        switch ($statusId) {
            case Review::STATUS_APPROVED:
                return 'Approved';
            case Review::STATUS_PENDING:
                return 'Pending';
            case Review::STATUS_NOT_APPROVED:
                return 'Not Approved';
            default:
                return "Unknown ({$statusId})";
        }
    }
}
