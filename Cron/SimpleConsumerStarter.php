<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Magento\Framework\MessageQueue\ConsumerFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Simple cron to process and republish queue messages
 */
class SimpleConsumerStarter
{
    private const CONSUMERS = [
        'loyaltyshop_free_product_purchase_event_consumer',
        'loyaltyshop_free_product_remove_event_consumer',
    ];

    private const MAX_MESSAGES_PER_CONSUMER = 10;
    private const PENDING_MESSAGE_STATUSES = [5, 2]; // 5 = retry required, 2 = new

    /**
     * Constructor
     *
     * @param ConsumerFactory $consumerFactory
     * @param LoyaltyHelper $loyaltyHelper
     * @param ResourceConnection $resourceConnection
     * @param PublisherInterface $publisher
     */
    public function __construct(
        protected ConsumerFactory $consumerFactory,
        protected LoyaltyHelper $loyaltyHelper,
        protected ResourceConnection $resourceConnection,
        protected PublisherInterface $publisher,
    ) {
    }

    /**
     * Execute cron job
     *
     * @return void
     */
    public function execute(): void
    {
        $this->loyaltyHelper->log(
            'info',
            'SimpleConsumerStarter',
            'execute',
            'SimpleConsumerStarter cron started'
        );
        
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $startTime = microtime(true);

        try {
            foreach (self::CONSUMERS as $consumerName) {
                $this->loyaltyHelper->log(
                    'info',
                    'SimpleConsumerStarter',
                    'execute',
                    "Processing consumer: {$consumerName}"
                );
                try {
                    $this->processConsumer($consumerName);
                } catch (\Exception $e) {
                    $this->loyaltyHelper->log(
                        "error",
                        "SimpleConsumerStarter",
                        "execute",
                        "Error processing consumer {$consumerName}: " . $e->getMessage()
                    );
                    continue;
                }
            }

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->loyaltyHelper->log(
                "debug",
                "SimpleConsumerStarter",
                "execute",
                "[LoyaltyShop] Queue Consumer - Completed"
            );

        } catch (\Exception $e) {
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "execute",
                "[LoyaltyShop] Queue Consumer Error: " . $e->getMessage()
            );
        }
    }

    /**
     * Process individual consumer
     *
     * @param string $consumerName
     * @return void
     */
    private function processConsumer(string $consumerName): void
    {        
        try {
            $queueName = $this->getQueueNameForConsumer($consumerName);
            $messageCount = $this->getQueueMessageCount($queueName);

            if ($messageCount == 0) {
                return;
            }
            $this->processMessagesWithPublisher(
                $consumerName,
                $queueName,
                $messageCount
            );

            $this->loyaltyHelper->log(
                "debug",
                "SimpleConsumerStarter",
                "processConsumer",
                "[LoyaltyShop] Consumer completed: " . $consumerName
            );

        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "processConsumer",
                "ERROR in {$consumerName}: " . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Process messages using publisher
     *
     * @param string $topicName
     * @param string $queueName
     * @param int $messageCount
     * @return void
     */
    private function processMessagesWithPublisher(
        string $topicName,
        string $queueName,
        int $messageCount
    ): void {
        try {
            $connection = $this->resourceConnection->getConnection();
            $queueId = $this->getQueueId($queueName);

            if (!$queueId) {
                return;
            }

            $messages = $this->getPendingMessages($queueId);

            foreach ($messages as $messageData) {
                try {
                    $this->republishMessage($queueName, $messageData);
                } catch (\Exception $e) {
                    $this->loyaltyHelper->log(
                        "error",
                        "SimpleConsumerStarter",
                        "processMessagesWithPublisher",
                        "Error republishing message ID {$messageData['id']}: "
                        . $e->getMessage()
                    );
                    continue;
                }
            }

        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "processMessagesWithPublisher",
                "Error in publisher processing: " . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Republish message to topic
     *
     * @param string $topicName
     * @param array $messageData
     * @return void
     */
    private function republishMessage(string $topicName, array $messageData): void
    {
        $messageId = $messageData['id'];
        $body = $messageData['body'];
        $decodedBody = json_decode($body, true);

        if ($decodedBody !== null) {
            $bodyToPublish = $decodedBody;
        } else {
            $bodyToPublish = $body;
        }
        $this->loyaltyHelper->log(
            "debug",
            "SimpleConsumerStarter",
            "republishMessage",
            "Successfully decoded body: " . $body
        );
        $this->publisher->publish($topicName, $bodyToPublish);

        $this->loyaltyHelper->log(
            "debug",
            "SimpleConsumerStarter",
            "republishMessage",
            "Successfully republished message ID: {$messageId}"
        );
    }

    /**
     * Get queue ID by name
     *
     * @param string $queueName
     * @return int|null
     */
    private function getQueueId(string $queueName): ?int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $queueTable = $this->resourceConnection->getTableName('queue');

            return (int) $connection->fetchOne(
                $connection->select()
                    ->from($queueTable, ['id'])
                    ->where('name = ?', $queueName)
            );
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "getQueueId",
                "Error getting queue ID: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Get pending messages from queue
     *
     * @param int $queueId
     * @return array
     */
    private function getPendingMessages(int $queueId): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $messageTable = $this->resourceConnection->getTableName('queue_message');
            $statusTable = $this->resourceConnection->getTableName('queue_message_status');

            return $connection->fetchAll(
                $connection->select()
                    ->from(['qm' => $messageTable], ['id', 'topic_name', 'body'])
                    ->join(
                        ['qms' => $statusTable],
                        'qm.id = qms.message_id',
                        ['status']
                    )
                    ->where('qms.queue_id = ?', $queueId)
                    ->where('qms.status IN (?)', self::PENDING_MESSAGE_STATUSES)
                    ->limit(self::MAX_MESSAGES_PER_CONSUMER)
            );
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "getPendingMessages",
                "Error getting pending messages: " . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Get queue message count
     *
     * @param string $queueName
     * @return int
     */
    private function getQueueMessageCount(string $queueName): int
    {
        try {
            $queueId = $this->getQueueId($queueName);

            if (!$queueId) {
                return 0;
            }

            $connection = $this->resourceConnection->getConnection();
            $statusTable = $this->resourceConnection->getTableName('queue_message_status');

            $count = $connection->fetchOne(
                $connection->select()
                    ->from($statusTable, ['COUNT(*)'])
                    ->where('queue_id = ?', $queueId)
                    ->where('status IN (?)', self::PENDING_MESSAGE_STATUSES)
            );

            return (int) $count;
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                "error",
                "SimpleConsumerStarter",
                "getQueueMessageCount",
                "Error checking queue messages: " . $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Map consumer name to queue name
     *
     * @param string $consumerName
     * @return string
     */
    private function getQueueNameForConsumer(string $consumerName): string
    {
        $consumerQueueMap = [
            'loyaltyshop_free_product_purchase_event_consumer' =>
                'loyaltyshop.free_product_purchase_event',
            'loyaltyshop_free_product_remove_event_consumer' =>
                'loyaltyshop.free_product_remove_event',
        ];

        return $consumerQueueMap[$consumerName] ?? 'unknown';
    }
}
