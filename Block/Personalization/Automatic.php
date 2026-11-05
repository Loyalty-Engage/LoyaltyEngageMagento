<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Personalization;

class Automatic extends Bootstrap
{
    public function targets(): string
    {
        if (!$this->rules()) { return ''; }
        $page = json_decode($this->settings(), true)['page'];
        $html = '';
        foreach ($this->rules() as $rule) {
            if ($rule['placement'] !== 'automatic' || $rule['page_scope'] !== $page['scope']
                || ($page['scope'] === 'pages' && !in_array($page['path'], explode(',', $rule['pages']), true))) {
                continue;
            }
            $html .= $this->getLayout()->createBlock(\LoyaltyEngage\LoyaltyShop\Block\Widget\Personalization::class)
                ->setData('key', $rule['key'])->setData('automatic', true)->toHtml();
        }
        return $html;
    }
}
