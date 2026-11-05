<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Widget;

use Magento\Framework\View\Element\Template;

class Personalization extends Template implements \Magento\Widget\Block\BlockInterface
{
    protected $_template = 'LoyaltyEngage_LoyaltyShop::personalization/target.phtml';
    private static int $sequence = 0;

    public function __construct(Template\Context $context,
        private \LoyaltyEngage\LoyaltyShop\Model\Personalization\Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function target(): ?array
    {
        $rule = $this->config->rule((string) $this->getData('key'), (int) $this->_storeManager->getStore()->getId());
        if (!$rule || ($rule['placement'] === 'automatic') !== (bool) $this->getData('automatic')) {
            return null;
        }
        return ['key' => $rule['key'], 'id' => 'le-personalization-' . $rule['key'] . '-' . ++self::$sequence];
    }
}
