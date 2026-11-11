<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

// Magento 2.4.6 has no reset interface. Keep the module loadable there, while
// opting into stateful-worker cleanup on frameworks that provide this contract.
if (interface_exists(\Magento\Framework\ObjectManager\ResetAfterRequestInterface::class)) {
    interface ResetInterface extends \Magento\Framework\ObjectManager\ResetAfterRequestInterface
    {
    }
} else {
    interface ResetInterface
    {
        public function _resetState(): void;
    }
}
