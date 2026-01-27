<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\DeploymentConfig\Writer as DeploymentConfigWriter;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\File\ConfigFilePool;
use Psr\Log\LoggerInterface;

/**
 * Configure cron consumers runner for LoyaltyShop queue consumers
 * This ensures queue messages are processed automatically via Magento cron
 */
class ConfigureCronConsumers implements DataPatchInterface
{
    /**
     * LoyaltyShop consumers that need to be processed
     */
    private const LOYALTY_CONSUMERS = [
        'loyaltyshop_free_product_purchase_event_consumer',
        'loyaltyshop_free_product_remove_event_consumer',
    ];

    /**
     * @param DeploymentConfigWriter $deploymentConfigWriter
     * @param DeploymentConfig $deploymentConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private DeploymentConfigWriter $deploymentConfigWriter,
        private DeploymentConfig $deploymentConfig,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        try {
            $this->configureCronConsumersRunner();
            $this->logger->info('[LoyaltyShop] Cron consumers runner configured successfully');
        } catch (\Exception $e) {
            // Log but don't fail - manual configuration can be done
            $this->logger->warning(
                '[LoyaltyShop] Could not auto-configure cron consumers runner: ' . $e->getMessage() .
                ' Please manually configure cron_consumers_runner in app/etc/env.php'
            );
        }

        return $this;
    }

    /**
     * Configure cron_consumers_runner in env.php
     *
     * @return void
     */
    private function configureCronConsumersRunner(): void
    {
        // Get existing cron_consumers_runner config
        $existingConfig = $this->deploymentConfig->get('cron_consumers_runner', []);
        
        // Ensure cron_run is enabled
        $existingConfig['cron_run'] = true;
        
        // Set max messages if not already set
        if (!isset($existingConfig['max_messages'])) {
            $existingConfig['max_messages'] = 100;
        }
        
        // Get existing consumers list or create new
        $existingConsumers = $existingConfig['consumers'] ?? [];
        
        // Add our consumers if not already present
        foreach (self::LOYALTY_CONSUMERS as $consumer) {
            if (!in_array($consumer, $existingConsumers)) {
                $existingConsumers[] = $consumer;
            }
        }
        
        $existingConfig['consumers'] = $existingConsumers;
        
        // Write the updated config
        $this->deploymentConfigWriter->saveConfig(
            [ConfigFilePool::APP_ENV => ['cron_consumers_runner' => $existingConfig]],
            true
        );
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
