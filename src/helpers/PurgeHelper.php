<?php

namespace vaersaagod\cloudflaremate\helpers;

use craft\errors\SiteNotFoundException;
use craft\models\Site;

use yii\base\Exception;
use yii\base\InvalidConfigException;

final class PurgeHelper
{

    /**
     * Purges a single URI, and deletes any record of it from the database
     *
     * @param string $uri
     * @param Site|string|int|null $site
     * @return bool
     * @throws Exception
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public static function purgeUri(string $uri, Site|string|int|null $site = null): bool
    {
        return PurgeHelper::purgeUris([$uri], $site);
    }

    /**
     * Purges an array of URIs, and deletes any records of them from the database
     *
     * @param array $uris
     * @param Site|string|int|null $site
     * @return bool
     * @throws Exception
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     */
    public static function purgeUris(array $uris, Site|string|int|null $site = null): bool
    {
        $site = SiteHelper::getSiteFromParam($site);
        // Make sure all URLs to actually purge, are fully qualified
        $urls = array_map(static fn(string $url) => UrlHelper::getFullyQualifiedUrl($url, $site->id), $uris);
        if (!PurgeHelper::purgeUrls($urls, $site)) {
            return false;
        }
        // Delete the purged URIs from the database log
        UrisHelper::deleteUris($uris, $site->id);
        return true;
    }

    /**
     * Purges a single, fully qualified URL
     *
     * @param string $url
     * @param Site|string|int|null $site
     * @return bool
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public static function purgeUrl(string $url, Site|string|int|null $site = null): bool
    {
        return PurgeHelper::purgeUrls([$url], $site);
    }

    /**
     * Purges an array of fully qualified URLs
     *
     * @param array $urls
     * @param Site|string|int|null $site
     * @return bool
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public static function purgeUrls(array $urls, Site|string|int|null $site = null): bool
    {
        $zoneId = SiteHelper::getZoneIdForSite($site);
        return ApiHelper::deleteFiles($zoneId, $urls);
    }

    /**
     * Purges an entire site, and deletes any URIs from that site from the database
     *
     * @param Site|string|int|null $site
     * @return void
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     */
    public static function purgeSite(Site|string|int|null $site): void
    {
        $site = SiteHelper::getSiteFromParam($site);
        $zoneId = SiteHelper::getZoneIdForSite($site);
        if (ApiHelper::purgeZone($zoneId)) {
            UrisHelper::deleteAllUrisForSite($site->id);
        }
    }
}
