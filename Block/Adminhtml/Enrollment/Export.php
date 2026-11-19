<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Enrollment;

use Magento\Backend\Block\Template;

class Export extends Template
{
    public function getExportStores(): array
    {
        return $this->_storeManager->getStores();
    }
}
