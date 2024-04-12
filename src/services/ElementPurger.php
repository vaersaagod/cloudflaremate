<?php

namespace vaersaagod\cloudflaremate\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\GlobalSet;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;

use vaersaagod\cloudflaremate\helpers\ApiHelper;
use vaersaagod\cloudflaremate\helpers\CloudflareMateHelper;
use vaersaagod\cloudflaremate\helpers\UrlHelper;
use vaersaagod\cloudflaremate\jobs\PurgeUrisJob;

use vaersaagod\cloudflaremate\jobs\PurgeZoneJob;

use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Purge service
 */
class ElementPurger extends Component
{

    /** @var int */
    private const CLOUDFLARE_URLS_PER_MINUTE_LIMIT = 1000;

    /** @var array */
    private array $_stuffToPurge;

    /**
     * @return void
     */
    public function init(): void
    {
        parent::init();

        if (isset($this->_stuffToPurge)) {
            return;
        }

        $this->_stuffToPurge = [];

        Craft::$app->onInit(function () {
            Event::on(
                Craft::$app::class,
                Craft::$app::EVENT_AFTER_REQUEST,
                function (Event $event) {
                    try {
                        $this->_purgeInternal();
                    } catch (\Throwable $e) {
                        if (App::devMode()) {
                            throw $e;
                        }
                        Craft::error("Failed to purge URIs", __METHOD__);
                        Craft::error($e, __METHOD__);
                    }
                }
            );
        });
    }

    /**
     * Finds all URIs to purge for a single element, and sticks them in a memoised array of URIs pending purge.
     * A queue job is created at the end of the request, to purge all URIs pending.
     *
     * @param ElementInterface $element
     * @return void
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     * @throws \yii\db\Exception
     * @throws \Exception
     */
    public function purgeElementUris(ElementInterface $element): void
    {
        if (
            empty($element->id) ||
            ElementHelper::isDraftOrRevision($element) ||
            $element instanceof Asset && $element->getScenario() === Asset::SCENARIO_INDEX
        ) {
            return;
        }

        $site = $element->getSite();

        // If this is a global set; the entire zone will be purged so lets just end this here.
        if ($element instanceof GlobalSet) {
            $this->_stuffToPurge['sites'][] = $site->id;
            return;
        }

        // Queue up the element's ID for purging
        $this->_stuffToPurge[$site->handle]['elementIds'][] = $element->id;

        // If the element has an owner, queue up that as well
        if ($ownerId = $element->ownerId ?? null) {
            $this->_stuffToPurge[$site->handle]['elementIds'][] = $ownerId;
        }

        // If the element has a URL; queue up its URI for purging
        $elementUrl = rtrim($element->getUrl() ?? '', '/');
        if (!empty($elementUrl)) {
            if ($elementUriForSite = Craft::$app->getElements()->getElementUriForSite($element->id, $element->siteId)) {
                $this->_stuffToPurge[$site->handle]['uris'][] = $elementUriForSite;
            }
            $elementUri = UrlHelper::getUriFromFullUrl($elementUrl, $site);
            if ($elementUri && $elementUri !== $elementUriForSite) {
                $this->_stuffToPurge[$site->handle]['uris'][] = $elementUri;
            }
        }

    }

    /**
     * @return void
     */
    private function _purgeInternal(): void
    {

        if (!isset($this->_stuffToPurge)) {
            return;
        }

        // First, purge sites
        $sitesToPurge = array_unique($this->_stuffToPurge['sites'] ?? []);
        if (!empty($sitesToPurge)) {
            foreach ($sitesToPurge as $siteId) {
                $site = Craft::$app->getSites()->getSiteById($siteId, true);
                Queue::push(
                    new PurgeZoneJob([
                        'siteId' => $siteId,
                    ])
                );
                unset($this->_stuffToPurge[$site->handle]);
            }
        }
        unset($this->_stuffToPurge['sites']);

        // Then, purge URIs
        $delay = 0;
        $urisCounter = 0;
        foreach ($this->_stuffToPurge as $siteHandle => $stuff) {
            $uris = $stuff['uris'] ?? null;
            $elementIds = $stuff['elementIds'] ?? null;
            if (empty($uris) && empty($elementIds)) {
                continue;
            }
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle, true);
            $urisToPurge = $this->_getAllElementUrisToPurge($uris, $elementIds, $site->id);
            if (empty($urisToPurge)) {
                continue;
            }

            // Don't have too many URIs per job
            $urisToPurgeBatched = array_chunk($urisToPurge, 10 * ApiHelper::API_URLS_PER_REQUEST_LIMIT);

            foreach ($urisToPurgeBatched as $urisToPurgeBatch) {
                Queue::push(
                    job: new PurgeUrisJob([
                        'siteId' => $site->id,
                        'uris' => $urisToPurgeBatch,
                    ]),
                    delay: ($delay * 60)
                );
            }

            // For every thousand URI, add a minute delay
            $urisCounter += count($urisToPurge);
            $delay = floor($urisCounter / self::CLOUDFLARE_URLS_PER_MINUTE_LIMIT);
        }

        $this->_stuffToPurge = [];

    }

    /**
     * @param array $uris
     * @param array $elementIds
     * @param int $siteId
     * @return array
     */
    private function _getAllElementUrisToPurge(array $uris, array $elementIds, int $siteId): array
    {

        if (empty($uris) && empty($elementIds)) {
            return [];
        }

        // Get additional URIs from relations
        $relationUris = CloudflareMateHelper::getUrisFromElementRelations($elementIds, $siteId);

        $uris = array_unique([
            ...$uris,
            ...$relationUris,
        ]);

        // Get additional URIs to purge as per the `additionalUrisToPurge` config setting
        $purgePatternUris = CloudflareMateHelper::getAdditionalUrisToPurge($uris);

        $uris = array_unique([
            ...$uris,
            ...$purgePatternUris,
        ]);

        // Get additional URIs from the uris database table, that begins with any of our uris
        $prefixUrls = CloudflareMateHelper::getLoggedUrisByPrefix($uris, $siteId);

        $uris = array_unique([
            ...$uris,
            ...$prefixUrls,
        ]);

        // Finally, strip out any uris that we want to ignore
        $uris = array_filter($uris, static fn(string $uri) => !CloudflareMateHelper::shouldUriBeIgnored($uri));

        return array_values($uris);

    }

}
