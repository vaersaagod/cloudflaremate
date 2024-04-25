<?php

namespace vaersaagod\cloudflaremate\utilities;

use Craft;
use craft\base\Utility;
use craft\web\View;

/**
 * Cloudflare Mate Utility utility
 */
class CloudflareMateUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Cloudflare';
    }

    static function id(): string
    {
        return 'cloudflare-mate-utility';
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@appicons/world.svg');
    }

    static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('_cloudflaremate/utility.twig');
    }
}
