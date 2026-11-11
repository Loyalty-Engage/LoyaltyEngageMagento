<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;

class Submit
{
    public function __construct(
        private Selection $selection,
        private Config $config,
        private Money $money,
        private LockManagerInterface $locks,
        private LoggerInterface $logger,
        private \Magento\Quote\Api\CartRepositoryInterface $quotes,
        private \LoyaltyEngage\LoyaltyShop\Model\Wallet\Funding $funding
    ) {
    }

    public function aroundSubmit(QuoteManagement $subject, callable $proceed, Quote $quote, $orderData = [])
    {
        $row = $this->selection->row((int) $quote->getId());
        if (!$row || ((int) $row['requested_cents'] === 0 && $row['status'] === 'selected')) {
            return $proceed($quote, $orderData);
        }
        $name = 'le-wallet-quote-' . $quote->getId();
        if (!$this->locks->lock($name, 5)) {
            throw new LocalizedException(__('An order is already being placed for this cart. Please wait.'));
        }
        $ownerLock = 'le-wallet-owner-' . $quote->getStoreId() . '-' . $quote->getCustomerId();
        $ownerLocked = false;
        try {
            $ownerLocked = $this->locks->lock($ownerLock, 5);
            if (!$ownerLocked) {
                throw new LocalizedException(__('Another wallet payment is in progress. Please wait before retrying.'));
            }
            $this->selection->release((int) $quote->getId());
            $row = $this->selection->row((int) $quote->getId());
            if ($row['status'] === 'committed') {
                throw new LocalizedException(__('This cart has already been ordered. Please check My Orders.'));
            }
            if ($row['status'] === 'review') {
                throw new LocalizedException(__('This cart has a wallet reservation requiring shop review. Other unreserved wallet credit remains available.'));
            }
            if (!$this->selection->eligible($quote)) {
                $this->selection->forget($quote);
                $quote->setTotalsCollectedFlag(false)->collectTotals();
                $this->quotes->save($quote);
                throw new LocalizedException(__('Wallet payment is no longer available for this cart. Review the updated total and payment method before retrying.'));
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $amount = (int) $quote->getData(Selection::AMOUNT);
            if ($amount > 0) {
                $gross = $this->money->native($quote->getBaseGrandTotal()) + $amount;
                if ($amount !== $this->config->payable(min((int) $row['requested_cents'], $gross), (int) $quote->getStoreId())) {
                    throw new LocalizedException(__('Your wallet balance changed. Please review and apply your wallet amount again.'));
                }
                $method = (string) $quote->getPayment()->getMethod();
                if (!in_array($method, $this->config->paymentMethods((int) $quote->getStoreId()), true)
                    || ($amount === $gross && $method !== 'free') || ($amount < $gross && $method === 'free')) {
                    throw new LocalizedException(__('Please select a supported payment method after applying wallet credit.'));
                }
                $quote->reserveOrderId();
                $hold = $this->selection->reserve($quote, $amount, $gross);
                $this->funding->prepare($quote);
                $quote->setData(Selection::HOLD, $hold);
            } elseif ($this->selection->eligible($quote) && (int) $row['requested_cents'] > 0
                && (int) $row['store_id'] === (int) $quote->getStoreId() && (float) $quote->getBaseGrandTotal() > 0) {
                throw new LocalizedException(__('No wallet credit is currently available. Remove the wallet selection and retry.'));
            }
            $result = $proceed($quote, $orderData);
            if (!$result) {
                $this->selection->release((int) $quote->getId());
            }
            return $result;
        } catch (\Throwable $exception) {
            try {
                $this->selection->release((int) $quote->getId());
            } catch (\Throwable $releaseError) {
                $this->logger->critical('Wallet hold recovery required', ['quote_id' => (int) $quote->getId(), 'exception' => $releaseError]);
            }
            if ($exception instanceof \LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException) {
                throw new LocalizedException(__($exception->getCustomerMessage()), $exception);
            }
            throw $exception;
        } finally {
            $quote->unsetData(Selection::HOLD);
            if ($ownerLocked) { $this->locks->unlock($ownerLock); }
            $this->locks->unlock($name);
        }
    }
}
