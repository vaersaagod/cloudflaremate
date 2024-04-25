<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\helpers\App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

use vaersaagod\cloudflaremate\CloudflareMate;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;

final class ApiHelper
{

    /** @var int */
    public const API_URLS_PER_REQUEST_LIMIT = 30;

    /** @var string */
    private const API_ENDPOINT = 'https://api.cloudflare.com/client/v4/';

    /**
     * @param string $zoneId
     * @param array $files
     * @return bool
     * @throws InvalidConfigException
     */
    public static function deleteFiles(string $zoneId, array $files = []): bool
    {
        $client = ApiHelper::_getClient($zoneId);

        $files = array_values(array_unique($files));
        if (empty($files)) {
            return false;
        }

        Craft::info('Deleting ' . count($files) . ' files from Cloudflare: ' . json_encode($files), __METHOD__);

        // Batch files as per API limit
        $requests = [];
        $fileBatches = array_chunk($files, ApiHelper::API_URLS_PER_REQUEST_LIMIT);

        foreach ($fileBatches as $fileBatch) {
            $requests[] = new Request(
                'delete',
                '',
                [],
                json_encode(['files' => $fileBatch])
            );
        }

        // Create a pool of requests
        $pool = new Pool($client, $requests, [
            'fulfilled' => function () use (&$response) {
                $response = true;
            },
            'rejected' => function ($reason) {
                if ($reason instanceof RequestException) {
                    preg_match('/^(.*?)\R/', $reason->getMessage(), $matches);

                    if (!empty($matches[1])) {
                        Craft::error(trim($matches[1], ':'), __METHOD__);
                    }
                }
            },
        ]);

        $pool->promise()->wait();

        if ($success = $response ?? false) {
            Craft::info("Successfully deleted files from Cloudflare.", __METHOD__);
        } else {
            Craft::warning("Failed to delete files from Cloudflare.", __METHOD__);
        }

        return $success;

    }

    /**
     * @param string $zoneId
     * @return bool
     * @throws InvalidConfigException
     */
    public static function purgeZone(string $zoneId): bool
    {
        $client = ApiHelper::_getClient($zoneId);

        Craft::info("Purging the entire \"$zoneId\" zone from Cloudflare...", __METHOD__);

        $request = new Request(
            'delete',
            '',
            [],
            json_encode(['purge_everything' => true])
        );

        try {
            $client->send($request);
        } catch (\Throwable $e) {
            Craft::error("Failed to purge the \"$zoneId\" zone", __METHOD__);
            Craft::error($e, __METHOD__);
            return false;
        }

        Craft::info("Big success!", __METHOD__);

        return true;
    }

    /**
     * @param string $zoneId
     * @return Client
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    private static function _getClient(string $zoneId): Client
    {
        $zoneId = App::parseEnv($zoneId);
        if (empty($zoneId)) {
            throw new InvalidArgumentException("Zone ID is empty");
        }

        $settings = CloudflareMate::getInstance()->getSettings();
        $apiToken = App::parseEnv($settings->apiToken);

        if (empty($apiToken)) {
            throw new InvalidConfigException("No API token");
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
