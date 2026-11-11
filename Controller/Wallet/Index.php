<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use Magento\Customer\Controller\AbstractAccount;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

class Index extends AbstractAccount implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private PageFactory $pages,
        private Config $config,
        private StoreManagerInterface $stores
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pages->create();
        $page->getConfig()->getTitle()->set($this->config->name((int) $this->stores->getStore()->getId()));
        $page->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        return $page;
    }
}
