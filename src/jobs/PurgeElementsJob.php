<?php

namespace vaersaagod\cloudflaremate\jobs;

use Craft;
use craft\i18n\Translation;
use craft\queue\BaseJob;

/**
 * Purge Elements Job queue job
 */
class PurgeElementsJob extends BaseJob
{

    public array $elementIds;

    function execute($queue): void
    {
        // ...
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('site', 'Purging Cloudflare caches...');
    }
}
