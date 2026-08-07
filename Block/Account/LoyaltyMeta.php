<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Account;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;

class LoyaltyMeta extends Template
{
    public function __construct(
        Template\Context $context,
        private LoyaltyHelper $loyaltyHelper,
        private CustomerSession $customerSession,
        private CustomerRepositoryInterface $customerRepository,
        private CustomerLoyaltyDataProvider $customerLoyaltyDataProvider,
        private Json $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function canRender(): bool
    {
        return $this->loyaltyHelper->isFrontendLoyaltyMetaEnabled() && $this->customerSession->isLoggedIn();
    }

    public function canBootstrap(): bool
    {
        return $this->loyaltyHelper->isFrontendLoyaltyMetaEnabled();
    }

    public function getLoyaltyMeta(): array
    {
        if (!$this->canRender()) {
            return [];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$customerId) {
            return [];
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return [];
        }

        $meta = $this->customerLoyaltyDataProvider->getCustomerLoyaltyData(
            $customer,
            (int) $this->_storeManager->getStore()->getId()
        );

        return $this->loyaltyHelper->filterFrontendLoyaltyMeta($meta);
    }

    public function getLabels(): array
    {
        $labels = [];

        foreach ($this->loyaltyHelper->getFrontendLoyaltyFieldConfig() as $fieldCode => $config) {
            $labels[$fieldCode] = $config['label'];
        }

        return $labels;
    }

    public function getBlockTitle(): string
    {
        $title = trim((string) $this->getData('title'));

        return $title !== '' ? $title : $this->loyaltyHelper->getFrontendLoyaltyMetaTitle();
    }

    public function getLoyaltyMetaJson(): string
    {
        return $this->jsonSerializer->serialize($this->getLoyaltyMeta());
    }

    public function getLabelsJson(): string
    {
        return $this->jsonSerializer->serialize($this->getLabels());
    }
}
