<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Adminhtml\Customer;

use LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Enrollment\Button;
use Magento\Backend\App\Action;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = Button::RESOURCE;

    public function __construct(Action\Context $context, private CustomerRepositoryInterface $customers, private PageFactory $pages)
    {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('Magento_Customer::manage');
    }

    public function execute()
    {
        try {
            $customer = $this->customers->getById((int) $this->getRequest()->getParam('id'));
        } catch (NoSuchEntityException $exception) {
            return $this->resultRedirectFactory->create()->setPath('customer/index');
        }
        $page = $this->pages->create();
        $page->getConfig()->getTitle()->prepend(__('Loyalty enrollment'));
        $page->getLayout()->getBlock('loyaltyshop.admin.enrollment')->setData('customer', $customer);
        return $page;
    }
}
