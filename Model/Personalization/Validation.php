<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Personalization;

use Magento\Framework\Exception\LocalizedException;

class Validation
{
    public const UUID = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

    public function tenant(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (preg_match('/^' . self::UUID . '$/iD', $input)) {
            return strtolower($input);
        }
        // Recognize the official inline template without executing or storing JavaScript.
        if (preg_match('~^<script\s+type=([\x22\x27])text/javascript\1\s*>(.*?)</script>$~sD', $input, $inline)) {
            $body = preg_replace('/\s+/', '', str_replace("'", '"', $inline[2]));
            if (preg_match('~\}\)\(window,document,"script","(' . self::UUID . ')"\);$~iD', $body, $tenant)) {
                $expected = '((a,b,c,d,e,f)=>{a._lecloud=a._lecloud||{event:[],personalization:[]};'
                    . 'e=b.getElementsByTagName(c)[0];f=b.createElement(c);f.type="module";f.async=!0;'
                    . 'f.src="https://le-cloud.app/f/t/"+d+".js";e.parentNode.insertBefore(f,e);'
                    . '})(window,document,"script","' . $tenant[1] . '");';
                if ($body === $expected) {
                    return strtolower($tenant[1]);
                }
            }
        }
        // Accept only the official empty loader tag, never arbitrary executable content.
        if (preg_match('~^<script\s+([^<>]+)>\s*</script>$~iD', $input, $tag)) {
            $attributes = [];
            $remaining = preg_replace_callback('~([a-z][a-z0-9_-]*)(?:\s*=\s*([\x22\x27])(.*?)\2)?\s*~is',
                static function (array $match) use (&$attributes): string {
                    $name = strtolower($match[1]);
                    if (array_key_exists($name, $attributes)) {
                        throw new LocalizedException(__('Duplicate loader attribute.'));
                    }
                    $attributes[$name] = $match[3] ?? '';
                    return '';
                }, trim($tag[1]));
            if ($remaining === '' && !array_diff(array_keys($attributes), ['src', 'type', 'async'])
                && strtolower($attributes['type'] ?? '') === 'module'
                && preg_match('~^https://le-cloud\.app/f/t/(' . self::UUID . ')\.js$~iD', $attributes['src'] ?? '', $url)) {
                return strtolower($url[1]);
            }
        }
        throw new LocalizedException(__('Enter a tenant UUID or the official loader snippet from https://le-cloud.app/f/t/.'));
    }

    public function rules(array $rows): array
    {
        unset($rows['__empty']);
        if (count($rows) > 100) {
            throw new LocalizedException(__('Configure at most 100 personalizations per store.'));
        }
        $keys = [];
        $result = [];
        foreach ($rows as $rowId => $row) {
            if (!is_array($row)) {
                throw new LocalizedException(__('Invalid personalization row.'));
            }
            $key = trim((string) ($row['key'] ?? ''));
            $publicId = trim((string) ($row['public_id'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || isset($keys[$key])) {
                throw new LocalizedException(__('Personalization keys must be unique lowercase names, starting with a letter (maximum 64 characters).'));
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/D', $publicId)) {
                throw new LocalizedException(__('Enter a valid personalization Public ID, for example bp-12.'));
            }
            $placement = (string) ($row['placement'] ?? 'manual');
            $scope = (string) ($row['page_scope'] ?? 'product');
            if (!in_array($placement, ['manual', 'automatic'], true)
                || !in_array($scope, ['product', 'cart', 'checkout', 'pages'], true)) {
                throw new LocalizedException(__('Invalid personalization placement or page scope.'));
            }
            $pages = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($row['pages'] ?? ''))))));
            foreach ($pages as &$page) {
                if (strlen($page) > 255 || !preg_match('~^/(?:[a-zA-Z0-9_./%-]*)$~D', $page) || str_contains($page, '//')) {
                    throw new LocalizedException(__('Selected pages must be comma-separated storefront paths such as /membership or /about-us.'));
                }
                $page = '/' . trim($page, '/');
            }
            unset($page);
            if ($placement === 'automatic' && $scope === 'pages' && !$pages) {
                throw new LocalizedException(__('Select at least one path for automatic selected-page placement.'));
            }
            $keys[$key] = true;
            $result[$rowId] = ['key' => $key, 'public_id' => $publicId, 'placement' => $placement,
                'page_scope' => $scope, 'pages' => implode(',', $pages)];
            foreach (['enabled', 'personalized', 'use_cart_value'] as $flag) {
                if (!in_array((string) ($row[$flag] ?? '0'), ['0', '1'], true)) {
                    throw new LocalizedException(__('Personalization switches must be Yes or No.'));
                }
                $result[$rowId][$flag] = (int) ($row[$flag] ?? 0);
            }
        }
        return $result;
    }
}
