<?php

namespace vaersaagod\cloudflaremate\models;

use craft\base\Model;

/**
 * CloudflareMate settings
 */
class Settings extends Model
{

    /** @var string The Cache-Control header set for the request if/when CFM figures it can be cached */
    public string $cacheControlHeader = 'public, max-age=31536000'; // TODO validation. This is required

    /** @var string A Cloudflare API token (not key!) */
    public string $apiToken;

    /** @var array An array of key => value pairs, where the keys are identifiers and the values are Cloudflare zone IDs */
    public array $zones = [];

    /** @var array An array of key => value pairs, where the keys are site handles, and the values are zone identifiers */
    public array $zoneMap = [];

    /** @var string The identifier for the Cloudflare zone to use if no zones from the zone map matches the site being purged */
    public string $defaultZone = '';

    /** @var array An array of key => value pairs, where the keys are URI patterns, and the values are arrays of additional URIs to clear for those patterns */
    public array $additionalUrisToPurge = [];

    /** @var array An array of URIs (or URI patterns) to ignore completely, both from purging and from setting proper Cache-Control headers **/
    public array $ignoredUris = [];

}
