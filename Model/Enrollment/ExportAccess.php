<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager;

class ExportAccess
{
    public const RESOURCE = 'LoyaltyEngage_LoyaltyShop::customer_loyalty_export';

    public function __construct(
        private AuthorizationInterface $authorization,
        private Session $session,
        private Manager $modules
    ) {}

    public function isAllowed(): bool
    {
        if (!$this->authorization->isAllowed('Magento_Customer::manage')
            || !$this->authorization->isAllowed(self::RESOURCE)) {
            return false;
        }
        // The report reads outside the grid. On Adobe Commerce, require explicit global data scope.
        return !$this->modules->isEnabled('Magento_AdminGws')
            || in_array($this->session->getUser()?->getRole()->getData('gws_is_all'), [true, 1, '1'], true);
    }
}
