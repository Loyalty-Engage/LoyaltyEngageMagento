<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Backend;

use LoyaltyEngage\LoyaltyShop\Model\Referral\Invitation;
use Magento\Config\Model\ResourceModel\Config\Data;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class InvitationUrl extends Value
{
    protected function _construct()
    {
        $this->_init(Data::class);
    }

    public function beforeSave()
    {
        $value = $this->getValue();
        $template = is_string($value) ? trim($value) : '';
        if (($value !== null && !is_string($value)) || ($template !== '' && !Invitation::isValidTemplate($template))) {
            throw new LocalizedException(__('Enter an HTTPS invitation URL with exactly one %1 in its path or query.', Invitation::PLACEHOLDER));
        }
        $this->setValue($template);
        return parent::beforeSave();
    }
}
