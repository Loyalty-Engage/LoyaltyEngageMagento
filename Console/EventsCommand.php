<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Console;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EventsCommand extends Command
{
    public function __construct(private ResourceConnection $resource, private EventOutbox $outbox, private StoreManagerInterface $stores)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('loyalty:events')->setDescription('Inspect Loyalty Engage delivery and redemption recovery.')
            ->addOption('retry', null, InputOption::VALUE_REQUIRED, 'Requeue one failed event by ID')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Resolve the store of an ambiguous legacy event')
            ->addOption('import-message', null, InputOption::VALUE_REQUIRED, 'Transfer a legacy DB queue message by ID')
            ->addOption('retry-mutation', null, InputOption::VALUE_REQUIRED, 'Retry local recovery of a confirmed response by operation key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->resource->getConnection();
        $table = $this->outbox->table();
        if ($id = $input->getOption('retry')) {
            $row = $db->fetchRow($db->select()->from($table)->where('entity_id = ?', (int) $id));
            if (!$row || $row['status'] !== 'failed') {
                $output->writeln('<error>Only failed events can be replayed.</error>');
                return 1;
            }
            if (!(int) $row['store_id']) {
                if (!$input->getOption('store')) {
                    $output->writeln('<error>This legacy message needs --store with the correct store ID or code.</error>');
                    return 1;
                }
                $storeId = (int) $this->stores->getStore($input->getOption('store'))->getId();
                if ($storeId <= 0) {
                    throw new \InvalidArgumentException('Choose a storefront store, not admin.');
                }
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $payload['store_id'] = $storeId;
                $db->beginTransaction();
                try {
                    $this->outbox->publish($row['topic'], json_encode($payload, JSON_THROW_ON_ERROR));
                    $db->update($table, ['status' => 'superseded'], ['entity_id = ?' => $id]);
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            } else {
                $db->update($table, ['status' => 'pending', 'attempts' => 0,
                    'available_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null], ['entity_id = ?' => $id]);
            }
            $output->writeln('Event queued for the next delivery cron.');
        }
        if ($id = $input->getOption('import-message')) {
            $row = $db->fetchRow($db->select()->from($this->resource->getTableName('queue_message'))
                ->where('id = ?', (int) $id));
            if (!$row || !in_array($row['topic_name'], EventOutbox::TOPICS, true)) {
                $output->writeln('<error>Not a Loyalty Engage DB queue message.</error>');
                return 1;
            }
            $states = array_map('intval', $db->fetchCol($db->select()
                ->from($this->resource->getTableName('queue_message_status'), ['status'])
                ->where('message_id = ?', (int) $id)));
            if (!$states || array_intersect($states, [3, 4, 7])) {
                $output->writeln('<error>Do not import messages that are processing, completed or scheduled for deletion.</error>');
                return 1;
            }
            $body = json_decode($row['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->outbox->publish($row['topic_name'], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
            $output->writeln('Legacy message copied to the outbox. Duplicate delivery is deduplicated by event identity.');
        }
        if ($key = $input->getOption('retry-mutation')) {
            $count = $db->update($this->resource->getTableName('loyaltyshop_mutation'),
                ['status' => 'received', 'attempts' => 0, 'available_at' => gmdate('Y-m-d H:i:s')],
                ['operation_key = ?' => $key, 'response IS NOT NULL', 'status IN (?)' => ['manual', 'received']]);
            $output->writeln($count ? 'Confirmed response queued for local recovery.' : 'No confirmed recoverable response found.');
        }
        $totals = $db->fetchAll($db->select()->from($table, ['status', 'total' => 'COUNT(*)'])->group('status'));
        $output->writeln($totals ? 'Event outbox:' : 'Event outbox: empty (no events recorded).');
        foreach ($totals as $row) {
            $output->writeln($row['status'] . ': ' . $row['total']);
        }
        foreach ($db->fetchAll($db->select()
            ->from(['m' => $this->resource->getTableName('queue_message')], ['topic_name'])
            ->joinInner(['s' => $this->resource->getTableName('queue_message_status')], 's.message_id = m.id',
                ['status', 'total' => 'COUNT(*)'])
            ->where('m.topic_name IN (?)', EventOutbox::TOPICS)->where('s.status IN (?)', [2, 3, 5, 6])
            ->group(['m.topic_name', 's.status'])) as $row) {
            $output->writeln('Legacy queue: ' . json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        }
        foreach ($db->fetchAll($db->select()->from($table, ['entity_id', 'topic', 'store_id', 'attempts', 'last_error'])
            ->where('status = ?', 'failed')->order('entity_id DESC')->limit(20)) as $row) {
            $output->writeln(json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        }
        foreach ($db->fetchAll($db->select()->from($this->resource->getTableName('loyaltyshop_mutation'),
            ['operation_key', 'operation', 'store_id', 'status', 'last_error'])
            ->where('status <> ?', 'applied')->order('created_at DESC')->limit(20)) as $row) {
            $output->writeln(json_encode($row, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        }
        return 0;
    }
}
