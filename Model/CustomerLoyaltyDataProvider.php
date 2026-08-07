<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class CustomerLoyaltyDataProvider
{
    public const ATTRIBUTE_CODES = [
        'le_current_tier',
        'le_points',
        'le_available_coins',
        'le_next_tier',
        'le_points_to_next_tier',
        'le_reserved_coins',
        'le_expiring_points_30d',
    ];

    private const TABLE_NAME = 'loyaltyshop_customer_store_data';

    public function __construct(
        private ResourceConnection $resourceConnection,
        private StoreManagerInterface $storeManager,
        private CustomerRepositoryInterface $customerRepository,
        private SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
    }

    public function resolveStoreId(?string $storeCode = null): int
    {
        if ($storeCode === null || $storeCode === '') {
            return (int) $this->storeManager->getStore()->getId();
        }

        return (int) $this->storeManager->getStore($storeCode)->getId();
    }

    public function resolveWebsiteId(?string $storeCode = null): int
    {
        if ($storeCode === null || $storeCode === '') {
            return (int) $this->storeManager->getStore()->getWebsiteId();
        }

        return (int) $this->storeManager->getStore($storeCode)->getWebsiteId();
    }

    public function getCustomerByEmail(string $email, ?string $storeCode = null): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, $this->resolveWebsiteId($storeCode));
        } catch (NoSuchEntityException) {
            $searchCriteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('email', $email)
                ->addFilter('website_id', $this->resolveWebsiteId($storeCode))
                ->setPageSize(1)
                ->create();

            $customers = $this->customerRepository->getList($searchCriteria)->getItems();
            $customer = reset($customers);

            return $customer instanceof CustomerInterface ? $customer : null;
        }
    }

    public function saveCustomerStoreData(int $customerId, int $storeId, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $row = array_merge($this->getScopedRow($customerId, $storeId), [
            'customer_id' => $customerId,
            'store_id' => $storeId,
        ]);

        foreach (self::ATTRIBUTE_CODES as $attributeCode) {
            if (array_key_exists($attributeCode, $data)) {
                $row[$attributeCode] = $data[$attributeCode];
            }
        }

        $connection->insertOnDuplicate($tableName, $row, self::ATTRIBUTE_CODES);
    }

    public function getCustomerLoyaltyData($customer, ?int $storeId = null): array
    {
        $customerId = (int) $customer->getId();
        if (!$customerId) {
            return [];
        }

        $resolvedStoreId = $storeId ?? (int) $this->storeManager->getStore()->getId();
        $scopedData = $this->getScopedRow($customerId, $resolvedStoreId);
        $resolvedData = [];

        foreach (self::ATTRIBUTE_CODES as $attributeCode) {
            if (array_key_exists($attributeCode, $scopedData) && $scopedData[$attributeCode] !== null) {
                $resolvedData[$attributeCode] = $scopedData[$attributeCode];
                continue;
            }

            $resolvedData[$attributeCode] = $this->extractAttributeValue($customer, $attributeCode);
        }

        return $resolvedData;
    }

    public function getAttributeValue($customer, string $attributeCode, ?int $storeId = null): mixed
    {
        $data = $this->getCustomerLoyaltyData($customer, $storeId);
        return $data[$attributeCode] ?? null;
    }

    private function getScopedRow(int $customerId, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);

        $select = $connection->select()
            ->from($tableName)
            ->where('customer_id = ?', $customerId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : [];
    }

    private function extractAttributeValue($customer, string $attributeCode): mixed
    {
        if ($customer instanceof CustomerInterface) {
            $attribute = $customer->getCustomAttribute($attributeCode);
            return $attribute ? $attribute->getValue() : null;
        }

        if (is_object($customer) && method_exists($customer, 'getData')) {
            return $customer->getData($attributeCode);
        }

        return null;
    }
}
