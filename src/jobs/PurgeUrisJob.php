<?php

namespace vaersaagod\cloudflaremate\jobs;

use Craft;
use craft\i18n\Translation;
use craft\queue\BaseJob;

use vaersaagod\cloudflaremate\helpers\ApiHelper;
use vaersaagod\cloudflaremate\helpers\PurgeHelper;

/**
 * Purge Elements Job queue job
 */
class PurgeUrisJob extends BaseJob
{

    /** @var int */
    public int $siteId;

    /** @var array An array of URIs to purge */
    public array $uris;

    /**
     * @param $queue
     * @return void
     */
    function execute($queue): void
    {
        // Create batches as per Cloudflare's URLs per request limit
        $uriBatches = array_chunk($this->uris, ApiHelper::API_URLS_PER_REQUEST_LIMIT);
        $total = count($this->uris);
        $count = 0;
        $step = 0;
        $steps = ceil($total / ApiHelper::API_URLS_PER_REQUEST_LIMIT);

        foreach ($uriBatches as $uriBatch) {
            $count += count($uriBatch);
            $step++;
            $this->setProgress($queue, $count / $total, Translation::prep('app', '{step, number} of {total, number}', [
                'step' => $step,
                'total' => $steps,
            ]));

            try {
                PurgeHelper::purgeUris($uriBatch, $this->siteId);
            } catch (\Throwable $e) {
                Craft::error($e, __METHOD__);
                continue;
            }
        }

    }

    /** @inheritdoc */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('site', 'Purging Cloudflare URIs...');
    }
}
