<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\Enroll;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Join extends AbstractAccount implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private Session $session,
        private Validator $formKey,
        private StoreManagerInterface $stores,
        private Enroll $enroll,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            if (!$this->formKey->validate($this->getRequest()) || !$this->session->isLoggedIn()
                || $this->getRequest()->getParam('loyalty_opt_in') !== '1') {
                throw new LocalizedException(__('Confirm that you want to join the loyalty program.'));
            }
            $status = $this->enroll->execute(
                (string) $this->session->getCustomerDataObject()->getEmail(),
                (int) $this->stores->getStore()->getId(),
                'account'
            );
            if ($status === 'pending') {
                $this->messageManager->addSuccessMessage(__('Your loyalty enrollment is being processed.'));
            } elseif ($status === 'failed') {
                $this->messageManager->addErrorMessage(__('Your loyalty enrollment could not be processed. Please contact the shop.'));
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to queue loyalty enrollment from My Account.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Loyalty enrollment could not be completed. Please try again.'));
        }
        return $this->resultRedirectFactory->create()->setPath('customer/account');
    }
}
