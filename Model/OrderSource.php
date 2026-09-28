<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\Area;

class OrderSource
{
    public const STOREFRONT = 'storefront';
    public const ADMIN = 'admin';
    public const API = 'api';
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::STOREFRONT,
        self::ADMIN,
        self::API,
        self::UNKNOWN,
    ];

    public function fromAreaCode(string $areaCode): string
    {
        if ($areaCode === Area::AREA_FRONTEND) {
            return self::STOREFRONT;
        }

        if ($areaCode === Area::AREA_ADMINHTML || $areaCode === Area::AREA_ADMIN) {
            return self::ADMIN;
        }

        if (in_array($areaCode, [Area::AREA_WEBAPI_REST, Area::AREA_WEBAPI_SOAP, Area::AREA_GRAPHQL], true)) {
            return self::API;
        }

        return self::UNKNOWN;
    }

    public function normalize(?string $source): string
    {
        return in_array($source, self::ALL, true) ? $source : self::UNKNOWN;
    }
}
