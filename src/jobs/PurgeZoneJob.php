<?php

namespace vaersaagod\cloudflaremate\jobs;

use Craft;
use craft\i18n\Translation;
use craft\queue\BaseJob;

use vaersaagod\cloudflaremate\helpers\PurgeHelper;

class PurgeZoneJob extends BaseJob
{

    public int $siteId;

    function execute($queue): void
    {
        try {
            PurgeHelper::purgeSite($this->siteId);
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
        }
    }

    /** @inheritdoc */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('site', 'Purging Cloudflare zone...');
    }

}
