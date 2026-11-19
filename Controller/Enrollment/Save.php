<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Controller\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Choice, CheckoutPreference};
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
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
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
            $token = $this->getRequest()->getParam('quote_token');
            if (!is_string($token) || !hash_equals(CheckoutPreference::token(
                (int) $quote->getId(), $storeId, (string) $this->getRequest()->getParam('form_key')
            ), $token)) {
                throw new LocalizedException(__('Your cart has changed. Refresh checkout and try again.'));
            }
            $selected = $this->getRequest()->getParam('subscribe');
            if (!in_array($selected, ['0', '1'], true)) { throw new LocalizedException(__('Invalid loyalty preference.')); }
            $this->choice->save((int) $quote->getId(), $storeId, $selected === '1', $mode, $this->config->label($storeId));
            return $result->setData(['success' => true]);
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('Unable to save loyalty enrollment preference.', ['exception' => $e]);
            return $result->setHttpResponseCode(500)->setData(['success' => false]);
        }
    }
}
