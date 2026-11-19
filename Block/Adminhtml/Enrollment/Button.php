<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Block\Adminhtml\Enrollment;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class Button implements ButtonProviderInterface
{
    public const RESOURCE = 'LoyaltyEngage_LoyaltyShop::customer_loyalty_enroll';

    public function __construct(
        private RequestInterface $request,
        private AuthorizationInterface $authorization,
        private UrlInterface $url
    ) {}

    public function getButtonData(): array
    {
        $id = (int) $this->request->getParam('id');
        if (!$id || !$this->authorization->isAllowed(self::RESOURCE)) {
            return [];
        }
        return [
            'label' => __('Loyalty enrollment'),
            'class' => 'secondary',
            'on_click' => 'location.href = ' . json_encode(
                $this->url->getUrl('loyaltyshop_enrollment/customer/index', ['id' => $id]),
                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
            ) . ';',
            'sort_order' => 35,
        ];
    }
}
