<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Controller\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Apply extends AbstractAccount implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private Validator $formKey,
        private Session $customer,
        private CheckoutSession $checkout,
        private StoreManagerInterface $stores,
        private CartRepositoryInterface $quotes,
        private Selection $selection,
        private Money $money,
        private ScopeConfigInterface $config,
        private LoggerInterface $logger,
        private \Magento\Framework\Lock\LockManagerInterface $locks
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $lock = null;
        $redirect = $this->resultRedirectFactory->create()->setPath(
            $this->getRequest()->getParam('return_to') === 'cart' ? 'checkout/cart' : 'checkout'
        );
        try {
            if (!$this->formKey->validate($this->getRequest())) {
                throw new LocalizedException(__('Invalid form key. Please refresh the page.'));
            }
            $amount = $this->getRequest()->getParam('amount', '0');
            if (!is_string($amount) || !preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/D', $amount)) {
                throw new LocalizedException(__('Enter a valid EUR amount with at most two decimal places.'));
            }
            $storeId = (int) $this->stores->getStore()->getId();
            $quote = $this->quotes->getActive((int) $this->checkout->getQuoteId(), [$storeId]);
            if (!$quote instanceof \Magento\Quote\Model\Quote || !$this->customer->isLoggedIn()
                || (int) $quote->getCustomerId() !== (int) $this->customer->getCustomerId()) {
                throw new LocalizedException(__('This cart does not belong to your account.'));
            }
            $name = 'le-wallet-quote-' . $quote->getId();
            if (!$this->locks->lock($name, 5)) {
                throw new LocalizedException(__('Your cart is being ordered. Please wait before changing wallet payment.'));
            }
            $lock = $name;
            $this->selection->choose($quote, $this->money->cents($amount));
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            if ((float) $quote->getBaseGrandTotal() < 0.0001) {
                if (!$this->config->isSetFlag('payment/free/active', 'store', $storeId)) {
                    $this->selection->choose($quote, 0);
                    $quote->setTotalsCollectedFlag(false)->collectTotals();
                    $this->quotes->save($quote);
                    throw new LocalizedException(__('Full wallet payment requires Zero Subtotal Checkout to be enabled by the shop.'));
                }
                $quote->getPayment()->setMethod('free');
            } elseif ($quote->getPayment()->getMethod() === 'free') {
                $quote->getPayment()->setMethod(null);
            }
            $this->quotes->save($quote);
            // The refreshed wallet field and totals confirm the change. A session
            // success message can remain unseen in Luma until the order success page.
        } catch (LocalizedException | \InvalidArgumentException $exception) {
            $this->messageManager->addErrorMessage(__($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to apply wallet credit', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('Wallet credit could not be updated. Please try again.'));
        } finally {
            if ($lock !== null) { $this->locks->unlock($lock); }
        }
        return $redirect;
    }
}
