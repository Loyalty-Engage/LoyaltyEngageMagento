<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Customer\Model\Session as CustomerSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ShippingMethodPlugin
{
    protected $customerSession;
    protected $helper;
    protected $curl;
    protected $logger;

    public function __construct(
        CustomerSession $customerSession,
        Data $helper,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->helper = $helper;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Check if customer qualifies for free shipping based on loyalty level
     *
     * @param string $customerEmail
     * @return bool
     */
    protected function customerQualifiesForFreeShipping(string $customerEmail): bool
    {
        $loyaltyLevels = $this->helper->getFreeShippingLoyaltyLevels();
        
        if (empty($loyaltyLevels)) {
            return false;
        }

        try {
            $clientId = $this->helper->getClientId();
            $clientSecret = $this->helper->getClientSecret();
            $apiUrl = rtrim($this->helper->getApiUrl(), '/');
            
            if (!$clientId || !$clientSecret || !$apiUrl) {
                return false;
            }

            $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
            
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            $this->curl->get("{$apiUrl}/api/v1/contact/{$customerEmail}/loyalty_status");

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();

            if ($status === 200) {
                $data = json_decode($response, true);
                
                if (isset($data['tier']) || isset($data['level'])) {
                    $customerLevel = $data['tier'] ?? $data['level'];
                    
                    return in_array($customerLevel, $loyaltyLevels);
                }
            }

            $this->logger->info("[LoyaltyShop] Loyalty status API response (HTTP $status): $response");
        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Loyalty status API Error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Plugin to modify shipping rates for loyalty customers
     *
     * @param \Magento\Shipping\Model\Shipping $subject
     * @param Result $result
     * @param RateRequest $request
     * @return Result
     */
    public function afterCollectRates(\Magento\Shipping\Model\Shipping $subject, Result $result, RateRequest $request)
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $result;
        }

        $customer = $this->customerSession->getCustomer();
        $customerEmail = $customer->getEmail();

        if (!$customerEmail) {
            return $result;
        }

        if ($this->customerQualifiesForFreeShipping($customerEmail)) {
            // Set all shipping methods to free for qualifying loyalty customers
            $rates = $result->getAllRates();
            
            foreach ($rates as $rate) {
                $rate->setPrice(0);
                $rate->setCost(0);
            }
        }

        return $result;
    }
}
