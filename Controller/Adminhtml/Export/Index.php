<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Adminhtml\Export;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\ExportAccess;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = ExportAccess::RESOURCE;

    public function __construct(Action\Context $context, private ExportAccess $access, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return $this->access->isAllowed();
    }

    public function execute()
    {
        $page = $this->pages->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Loyalty CSV Export'));
        return $page;
    }
}
