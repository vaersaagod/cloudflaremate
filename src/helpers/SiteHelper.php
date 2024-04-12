<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\models\Site;

use vaersaagod\cloudflaremate\CloudflareMate;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

final class SiteHelper
{

    /**
     * Returns a Site from a param that can be a Site instance, site handle or site ID. If `null` is passed, the current site is returned
     *
     * @param Site|string|int|null $param
     * @return Site
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public static function getSiteFromParam(Site|string|int|null $param): Site
    {
        if ($param instanceof Site) {
            return $param;
        }
        if (empty($param)) {
            $site = Craft::$app->getSites()->getCurrentSite();
        } else if (is_string($param)) {
            $site = Craft::$app->getSites()->getSiteByHandle($param);
        } else if (is_int($param)) {
            $site = Craft::$app->getSites()->getSiteById($param);
        }
        if (!empty($site)) {
            return $site;
        }
        throw new InvalidConfigException("Unable to determine site");
    }

    /**
     * @param Site|int|string|null $site
     * @return string
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public static function getZoneIdForSite(Site|int|string|null $site = null): string
    {
        $site = SiteHelper::getSiteFromParam($site);
        $settings = CloudflareMate::getInstance()->getSettings();
        $zones = $settings->zones;
        if (empty($zones)) {
            throw new InvalidArgumentException("No Cloudflare zones in config");
        }
        $zoneMap = $settings->zoneMap;
        foreach ($zoneMap as $siteHandles => $zoneHandle) {
            if (!is_array($siteHandles)) {
                $siteHandles = array_filter(explode(',', preg_replace('/\s+/', '', $siteHandles)));
            }
            foreach ($siteHandles as $siteHandle) {
                $siteHandle = trim($siteHandle);
                if (empty($siteHandle)) {
                    continue;
                }
                if ($siteHandle === $site->handle) {
                    $zoneId = App::parseEnv(trim($zones[$zoneHandle] ?? ''));
                    if (empty($zoneId)) {
                        throw new InvalidConfigException("Invalid zone handle \"$zoneHandle\"");
                    }
                    return trim($zoneHandle);
                }
            }
        }
        // No zone ID was found in the zone map, so return the default if it exists
        $defaultZone = App::parseEnv($zones[$settings->defaultZone] ?? '');
        if (empty($defaultZone)) {
            throw new InvalidConfigException("Unable to determine zone, and there is no default");
        }
        return $defaultZone;
    }
}
