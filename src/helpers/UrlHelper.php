<?php

namespace vaersaagod\cloudflaremate\helpers;

use craft\helpers\StringHelper;
use craft\helpers\UrlHelper as BaseUrlHelper;
use craft\models\Site;

final class UrlHelper
{

    /**
     * @param string $url
     * @param Site $site
     * @return string
     * @throws \Exception
     */
    public static function getUriFromFullUrl(string $url, Site $site): string
    {
        if (!UrlHelper::isAbsoluteUrl($url)) {
            throw new \Exception("This is not a full URL: \"$url\"");
        }
        $basePath = rtrim($site->getBaseUrl(), '/');
        return ltrim(substr($url, strlen($basePath)), '/');
    }

    /**
     * @param string $uri
     * @param int $siteId
     * @return string
     * @throws \yii\base\Exception
     */
    public static function getFullyQualifiedUrl(string $uri, int $siteId): string
    {
        if (UrlHelper::isAbsoluteUrl($uri)) {
            // It's already an absolute URL
            return $uri;
        }
        $addTrailingSlash = StringHelper::endsWith($uri, '/');
        if (empty($uri) || $uri === '/' || $uri === '__home__') {
            $url = BaseUrlHelper::siteUrl(siteId: $siteId);
        } else {
            $url = BaseUrlHelper::siteUrl($uri, siteId: $siteId);
        }
        if ($addTrailingSlash) {
            $url = StringHelper::ensureRight($url, '/');
        } else {
            $url = StringHelper::trimRight($url, '/');
        }
        return $url;
    }

    /**
     * @param string $url
     * @return bool
     */
    public static function isAbsoluteUrl(string $url): bool
    {
        return BaseUrlHelper::isAbsoluteUrl($url) || BaseUrlHelper::isProtocolRelativeUrl($url);
    }

}
