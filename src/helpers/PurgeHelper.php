<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\Site;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

use vaersaagod\cloudflaremate\CloudflareMate;

final class PurgeHelper
{

    private const API_ENDPOINT = 'https://api.cloudflare.com/client/v4/';

    private const API_URLS_PER_REQUEST_LIMIT = 30;

    private const API_URLS_PER_MINUTE_LIMIT = 1000; // This doesn't belong here. It should be done in a queue job, e.g. create batched job with a minute waiting time between each

    public static function url(string $url, ?int $siteId = null): bool
    {
        if (UrlHelper::isRootRelativeUrl($url)) {
            $url = UrlHelper::siteUrl($url, siteId: $siteId);
        }
        return PurgeHelper::urls([$url]);
    }

    /**
     * Purge an array of URLs
     *
     * @param array $urls
     * @return bool
     */
    public static function urls(array $urls): bool
    {
        return PurgeHelper::_purgeFiles($urls);
    }

    /**
     * Purges an entire site (i.e. an entire CloudFlare zone)
     *
     * @param int|Site|null $siteOrSiteId The site to purge the zone for, or its ID – or `null`, in which case the zone for the primary site will be purged.
     * @return bool
     */
    public static function site(int|Site|null $siteOrSiteId = null): bool
    {
        return PurgeHelper::_purgeZone($siteOrSiteId?->id);
    }

    private static function _purgeFiles(array $files = [], ?int $siteId = null): bool
    {
        $files = array_values(array_unique($files));
        if (empty($files)) {
            return false;
        }

        // Batch files sa per API limit
        $requests = [];
        $fileBatches = array_chunk($files, PurgeHelper::API_URLS_PER_REQUEST_LIMIT);

        foreach ($fileBatches as $fileBatch) {
            $requests[] = new Request(
                'delete',
                '',
                [],
                json_encode(['files' => $fileBatch])
            );
        }

        $client = PurgeHelper::_getClient($siteId);

        // Create a pool of requests
        $pool = new Pool($client, $requests, [
            'fulfilled' => function() use (&$response) {
                $response = true;
            },
            'rejected' => function($reason) {
                if ($reason instanceof RequestException) {
                    preg_match('/^(.*?)\R/', $reason->getMessage(), $matches);

                    if (!empty($matches[1])) {
                        Craft::error(trim($matches[1], ':'), __METHOD__);
                    }
                }
            },
        ]);

        $pool->promise()->wait();

        return $response ?? false;

    }

    /**
     * @param int|null $siteId
     * @return bool
     */
    private static function _purgeZone(?int $siteId): bool
    {
        $request = new Request(
            'delete',
            '',
            [],
            json_encode(['purge_everything' => true])
        );
        $client = PurgeHelper::_getClient($siteId);

        try {
            $client->send($request);
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return false;
        }

        return true;
    }

    private static function _getClient(?int $siteId = null): Client
    {
        $settings = CloudflareMate::getInstance()->getSettings();
        $siteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $zoneId = App::parseEnv($settings->zoneIds[$site?->handle] ?? null);

        if (empty($zoneId)) {
            throw new \RuntimeException("No zone ID");
        }

        $apiToken = App::parseEnv($settings->apiToken);

        if (empty($apiToken)) {
            throw new \RuntimeException("No API token");
        }

        $baseUri = self::API_ENDPOINT . "zones/$zoneId/purge_cache";

        return Craft::createGuzzleClient([
            'base_uri' => $baseUri,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiToken,
            ],
        ]);
    }

}
