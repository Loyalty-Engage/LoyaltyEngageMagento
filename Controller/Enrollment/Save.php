<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Controller\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Choice};
use Magento\Framework\Exception\LocalizedException;

class Save extends \Magento\Framework\App\Action\Action implements \Magento\Framework\App\Action\HttpPostActionInterface
{
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        private \Magento\Checkout\Model\Session $checkout,
        private \Magento\Customer\Model\Session $customer,
        private \Magento\Store\Model\StoreManagerInterface $stores,
        private \Magento\Framework\Data\Form\FormKey\Validator $formKey,
        private Config $config,
        private Choice $choice,
        private \Psr\Log\LoggerInterface $logger
    ) { parent::__construct($context); }

    public function execute()
    {
        try {
            if (!$this->formKey->validate($this->getRequest())) { throw new LocalizedException(__('Invalid form key. Please refresh the page.')); }
            $storeId = (int) $this->stores->getStore()->getId();
            $mode = $this->config->mode($storeId);
            $quote = $this->checkout->getQuote();
            if ($mode === 'disabled' || !$quote->getId() || !$quote->getIsActive()
                || (int) $quote->getStoreId() !== $storeId
                || (int) $quote->getCustomerId() !== (int) $this->customer->getCustomerId()) {
                throw new LocalizedException(__('This loyalty preference cannot be changed for this cart.'));
            }
            $selected = $this->getRequest()->getParam('subscribe', '0');
            if (!in_array($selected, ['0', '1'], true)) { throw new LocalizedException(__('Invalid loyalty preference.')); }
            $this->choice->save((int) $quote->getId(), $storeId, $selected === '1', $mode, $this->config->label($storeId));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Unable to save loyalty enrollment preference.', ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('Your loyalty preference could not be saved. Please try again.'));
        }
        return $this->resultRedirectFactory->create()->setPath('checkout');
    }
}
