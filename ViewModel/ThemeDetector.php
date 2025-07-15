<?php
namespace LoyaltyEngage\LoyaltyShop\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;

class ThemeDetector implements ArgumentInterface
{
    /**
     * @var Data
     */
    private $helper;

    /**
     * @param Data $helper
     */
    public function __construct(
        Data $helper
    ) {
        $this->helper = $helper;
    }

    /**
     * Check if the current theme is Hyvä or Hyva ChildTheme
     *
     * @return bool
     */
    public function isHyvaOrChildTheme(): bool
    {
        return $this->helper->isHyvaOrChildTheme();
    }

    /**
     * Get the appropriate template path based on the current theme
     *
     * @param string $lumaTemplate The template path for Luma theme
     * @param string $hyvaTemplate The template path for Hyvä theme
     * @return string
     */
    public function getThemeSpecificTemplate(string $lumaTemplate, string $hyvaTemplate): string
    {
        return $this->isHyvaTheme() ? $hyvaTemplate : $lumaTemplate;
    }
}
