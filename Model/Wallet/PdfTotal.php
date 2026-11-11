<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

class PdfTotal extends \Magento\Sales\Model\Order\Pdf\Total\DefaultTotal
{
    public function __construct(
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Tax\Model\Calculation $taxCalculation,
        \Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory $ordersFactory,
        private Display $display,
        array $data = []
    ) {
        parent::__construct($taxHelper, $taxCalculation, $ordersFactory, $data);
    }

    public function getAmount()
    {
        return $this->display->total($this->getSource())['value'] ?? 0;
    }

    public function getTotalsForDisplay()
    {
        $total = $this->display->total($this->getSource());
        return $total ? [['amount' => $this->getOrder()->formatPriceTxt($total['value']),
            'label' => $total['label'] . ':', 'font_size' => 7]] : [];
    }
}
