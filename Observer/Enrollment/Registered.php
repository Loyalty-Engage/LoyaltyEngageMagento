<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Enroll};
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\{Observer, ObserverInterface};
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Registered implements ObserverInterface
{
    public function __construct(
        private RequestInterface $request,
        private StoreManagerInterface $stores,
        private Config $config,
        private Enroll $enroll,
        private AccountManagementInterface $accounts,
        private ManagerInterface $messages,
        private LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getCustomer();
        if (!$customer instanceof CustomerInterface || $this->request->getParam('loyalty_opt_in') !== '1') {
            return;
        }
        $storeId = (int) $this->stores->getStore()->getId();
        if ($this->config->mode($storeId) !== 'required') {
            return;
        }
        try {
            if ($this->accounts->getConfirmationStatus($customer->getId()) === AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED) {
                $this->messages->addNoticeMessage(__('After confirming your account, you can join the loyalty program from My Account.'));
                return;
            }
            $this->enroll->execute((string) $customer->getEmail(), $storeId, 'registration');
        } catch (\Throwable $exception) {
            // Magento has already created the account; do not turn signup into a failure.
            $this->logger->error('Unable to queue loyalty enrollment at registration.', ['exception' => $exception]);
            $this->messages->addNoticeMessage(__('Loyalty enrollment could not be completed. You can try again from My Account.'));
        }
    }
}
