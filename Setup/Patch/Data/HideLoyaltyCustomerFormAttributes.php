<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use Magento\Customer\Model\Metadata\AttributeMetadataCache;
use Magento\Eav\Model\Config;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class HideLoyaltyCustomerFormAttributes implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $setup,
        private Config $eavConfig,
        private AttributeMetadataCache $metadataCache
    ) {
    }

    public function apply()
    {
        $connection = $this->setup->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from(['a' => $this->setup->getTable('eav_attribute')], ['attribute_id'])
                ->joinInner(
                    ['e' => $this->setup->getTable('eav_entity_type')],
                    'e.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('e.entity_type_code = ?', 'customer')
                ->where('a.attribute_code IN (?)', CustomerLoyaltyDataProvider::ATTRIBUTE_CODES)
        );
        if (!$ids) {
            return $this;
        }

        $connection->beginTransaction();
        try {
            // Keep values and API access intact; these are not customer-editable form fields.
            $connection->update(
                $this->setup->getTable('customer_eav_attribute'),
                ['is_visible' => 0],
                ['attribute_id IN (?)' => $ids]
            );
            $connection->delete(
                $this->setup->getTable('customer_form_attribute'),
                ['attribute_id IN (?)' => $ids, 'form_code <> ?' => 'adminhtml_customer']
            );
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        $this->eavConfig->clear();
        $this->metadataCache->clean();
        return $this;
    }

    public static function getDependencies()
    {
        return [AddCustomerLoyaltyAttributesV2::class];
    }

    public function getAliases()
    {
        return [];
    }
}
