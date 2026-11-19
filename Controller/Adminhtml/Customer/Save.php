<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Adminhtml\Customer;

use LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Enrollment\Button;
use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{AdminStores, Enroll};
use Magento\Backend\App\Action;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = Button::RESOURCE;

    public function __construct(
        Action\Context $context,
        private CustomerRepositoryInterface $customers,
        private AdminStores $stores,
        private Enroll $enroll,
        private Validator $formKey,
        private Session $adminSession,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('Magento_Customer::manage');
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = (int) $request->getParam('id');
        try {
            if (!$this->formKey->validate($request) || $request->getParam('loyalty_opt_in') !== '1') {
                throw new LocalizedException(__('Confirm that the customer agreed to join the loyalty program.'));
            }
            $customer = $this->customers->getById($id);
            $storeId = (int) $request->getParam('store_id');
            if (!isset($this->stores->forCustomer($customer)[$storeId])) {
                throw new LocalizedException(__('Choose an eligible store view.'));
            }
            $status = $this->enroll->execute((string) $customer->getEmail(), $storeId, 'admin', [
                'admin_user_id' => (int) $this->adminSession->getUser()->getId(),
            ]);
            if ($status === 'failed') {
                $this->messageManager->addErrorMessage(__('The previous enrollment failed. Check the loyalty outbox before replaying it.'));
            } else {
                $this->messageManager->addSuccessMessage($status === 'member'
                    ? __('The customer is already enrolled for this store.')
                    : __('Loyalty enrollment is being processed.'));
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to queue loyalty enrollment from customer administration.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Loyalty enrollment could not be completed.'));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index', ['id' => $id]);
    }
}
