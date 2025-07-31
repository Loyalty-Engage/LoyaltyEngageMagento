<?php
namespace LoyaltyEngage\LoyaltyShop\Controller\Config;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data;

class Messages implements HttpGetActionInterface
{
    protected $jsonFactory;
    protected $helper;

    public function __construct(
        JsonFactory $jsonFactory,
        Data $helper
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->helper = $helper;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        
        $config = [
            'success' => [
                'message' => $this->helper->getSuccessMessage(),
                'backgroundColor' => $this->helper->getSuccessColor(),
                'textColor' => $this->helper->getSuccessTextColor()
            ],
            'error' => [
                'message' => $this->helper->getErrorMessage(),
                'backgroundColor' => $this->helper->getErrorColor(),
                'textColor' => $this->helper->getErrorTextColor()
            ]
        ];

        return $result->setData($config);
    }
}
