<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Cart;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Customer\Model\Session as CustomerSession;
use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class Add implements HttpPostActionInterface
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
                    'message' => 'Please log in to add this product to your cart.'
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

            // Debug logging to check customer session
            $this->logger->info('LoyaltyShop Cart Add Debug:', [
                'customer_id' => $customerId,
                'is_logged_in' => $this->customerSession->isLoggedIn(),
                'customer_email' => $this->customerSession->getCustomer()->getEmail(),
                'sku' => $sku
            ]);

            // Use the existing loyalty cart service
            $response = $this->loyaltyCart->addProduct($customerId, $sku);

            return $result->setData([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('LoyaltyShop Cart Add Error: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customerSession->getCustomerId(),
                'request_data' => $this->request->getContent()
            ]);

            return $result->setData([
                'success' => false,
                'message' => 'An error occurred while adding the product to your cart.'
            ]);
        }
    }
}
