<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Discount;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Customer\Model\Session as CustomerSession;
use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class Claim implements HttpPostActionInterface
{
    private $request;
    private $jsonFactory;
    private $customerSession;
    private $loyaltyCart;
    private $loyaltyHelper;
    private $logger;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        CustomerSession $customerSession,
        LoyaltyCartInterface $loyaltyCart,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->customerSession = $customerSession;
        $this->loyaltyCart = $loyaltyCart;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        
        try {
            // Check if module is enabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
                return $result->setData([
                    'success' => false,
                    'message' => 'LoyaltyEngage module is disabled.'
                ]);
            }

            // Check if customer is logged in
            if (!$this->customerSession->isLoggedIn()) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Please log in to claim this discount.'
                ]);
            }

            // Get request data
            $postData = $this->request->getContent();
            $data = json_decode($postData, true);
            
            if (!$data || !isset($data['sku'])) {
                return $result->setData([
                    'success' => false,
                    'message' => 'SKU is required.'
                ]);
            }

            $customerId = (int) $this->customerSession->getCustomerId();
            $sku = $data['sku'];

            // Use the new buyDiscountCodeProduct method
            $response = $this->loyaltyCart->buyDiscountCodeProduct($customerId, $sku);

            return $result->setData([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('LoyaltyShop Discount Claim Error: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customerSession->getCustomerId(),
                'request_data' => $this->request->getContent()
            ]);

            return $result->setData([
                'success' => false,
                'message' => 'An error occurred while claiming the discount.'
            ]);
        }
    }
}
