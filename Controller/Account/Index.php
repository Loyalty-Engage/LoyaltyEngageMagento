<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Account;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends AbstractAccount implements HttpGetActionInterface
{
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        private PageFactory $resultPageFactory,
        private LoyaltyHelper $loyaltyHelper
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set($this->loyaltyHelper->getFrontendLoyaltyNavigationLabel());

        return $resultPage;
    }
}
