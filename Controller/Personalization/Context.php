<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Controller\Personalization;

use LoyaltyEngage\LoyaltyShop\Model\Personalization\Config;
use LoyaltyEngage\LoyaltyShop\Model\Personalization\Amounts;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\App\Action\HttpGetActionInterface;

class Context implements HttpGetActionInterface
{
    public function __construct(private \Magento\Framework\Controller\Result\JsonFactory $results,
        private \Magento\Customer\Model\Session $customerSession,
        private \Magento\Checkout\Model\Session $checkoutSession,
        private \Magento\Store\Model\StoreManagerInterface $stores,
        private \Magento\Framework\App\RequestInterface $request,
        private Config $config, private Amounts $amounts, private Data $helper,
        private \Magento\Quote\Api\CartRepositoryInterface $quotes)
    {
    }

    public function execute()
    {
        $result = $this->results->create();
        $result->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $storeId = (int) $this->stores->getStore()->getId();
        $rules = $this->config->rules($storeId);
        $data = [];
        if ($rules && $this->request->getParam('personalized') === '1'
            && array_filter($rules, static fn(array $r): bool => (bool) $r['personalized'])
            && $this->customerSession->isLoggedIn()) {
            $email = (string) $this->customerSession->getCustomer()->getEmail();
            if ($email !== '') {
                $data['identifier'] = $this->helper->hashEmail($email);
            }
        }
        if ($rules && $this->request->getParam('cart') === '1'
            && array_filter($rules, static fn(array $r): bool => (bool) $r['use_cart_value'])
            && $this->checkoutSession->getQuoteId()) {
            try {
                // CheckoutSession::getQuote() can collect and save totals; this GET must only read.
                $quote = $this->quotes->getActive((int) $this->checkoutSession->getQuoteId(), [$storeId]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                $data['cartValue'] = 0;
                return $result->setData($data);
            }
            $owner = (int) $quote->getCustomerId();
            $current = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : 0;
            if ((int) $quote->getStoreId() === $storeId && $owner === $current) {
                // Read the saved cart only: a personalization GET must never remove rewards or mutate totals.
                $data['cartValue'] = $this->amounts->cart($quote, $this->helper->isEventPriceIncludingTax($storeId),
                    $this->config->value('currency', $storeId));
            }
        } elseif ($rules && $this->request->getParam('cart') === '1' && !$this->checkoutSession->getQuoteId()) {
            $data['cartValue'] = 0;
        }
        return $result->setData($data);
    }
}
