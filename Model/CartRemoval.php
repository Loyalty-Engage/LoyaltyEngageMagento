<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class CartRemoval
{
    public function __construct(
        private StoreContext $context, private CartRepositoryInterface $quotes,
        private Data $helper, private LoyaltyCartResponseInterfaceFactory $responses,
        private LockManagerInterface $lock, private LoggerInterface $logger
    ) {
    }

    public function remove(int $customerId, ?string $sku = null, int $quantity = 1): LoyaltyCartResponseInterface
    {
        $response = $this->responses->create();
        if ($customerId <= 0 || $quantity <= 0 || $sku === '') {
            return $this->helper->errorResponse($response, 'Customer, SKU and quantity must be valid.', 'validation');
        }
        $key = 'le_cart_' . $customerId;
        if (!$this->helper->isLoyaltyEngageEnabled($this->context->storeId())) {
            return $this->helper->errorResponse($response, 'Loyalty Engage is disabled.', 'disabled', 403);
        }
        if (!$this->lock->lock($key, 5)) {
            return $this->helper->errorResponse($response, 'Your cart is being updated. Please retry.', 'busy', 409);
        }
        try {
            $this->context->customer($customerId);
            $quote = $this->quotes->getActiveForCustomer($customerId, [$this->context->storeId()]);
            $this->context->assertQuote($quote);
            foreach ($quote->getAllVisibleItems() as $item) {
                if (!$this->helper->isLoyaltyProduct($item, true) || ($sku !== null && $item->getSku() !== $sku)) {
                    continue;
                }
                if ($sku !== null && $quantity < (float) $item->getQty()) {
                    throw new LocalizedException(__('Remove the complete loyalty item.'));
                }
                $quote->removeItem($item->getId());
            }
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->quotes->save($quote);
            return $this->helper->successResponse($response, 'Loyalty items removed; balance update queued.');
        } catch (NoSuchEntityException $e) {
            return $this->helper->successResponse($response, 'No active loyalty cart found.');
        } catch (LocalizedException $e) {
            return $this->helper->errorResponse($response, $e->getMessage(), 'validation');
        } catch (\Throwable $e) {
            $this->logger->error('Loyalty cart removal failed.', ['error' => $e->getMessage()]);
            return $this->helper->errorResponse($response, 'Unable to save cart removal.', 'system_error', 500);
        } finally {
            $this->lock->unlock($key);
        }
    }
}
