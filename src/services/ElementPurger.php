<?php

namespace vaersaagod\cloudflaremate\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\MatrixBlock;
use craft\elements\User;
use craft\errors\SiteNotFoundException;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;

use vaersaagod\cloudflaremate\CloudflareMate;
use vaersaagod\cloudflaremate\helpers\ApiHelper;
use vaersaagod\cloudflaremate\helpers\CloudflareMateHelper;
use vaersaagod\cloudflaremate\helpers\UrlHelper;
use vaersaagod\cloudflaremate\jobs\PurgeUrisJob;

use vaersaagod\cloudflaremate\jobs\PurgeZoneJob;

use verbb\supertable\elements\SuperTableBlockElement;
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

        // Add additional URIs based on element sources
        $elementSourcesToPurge = CloudflareMate::getInstance()->getSettings()->elements;
        if (!empty($elementSourcesToPurge)) {
            $elementSources = [];
            if ($element instanceof MatrixBlock || $element instanceof SuperTableBlockElement) {
                try {
                    $element = $element->getOwner();
                } catch (\Throwable $e) {
                    Craft::error($e, __METHOD__);
                    return;
                }
            }
            if ($element instanceof Entry) {
                $sectionHandle = $element->getSection()?->handle;
                if ($sectionHandle) {
                    $elementSources[] = "section:$sectionHandle";
                }
                $typeHandle = $element->getType()?->handle;
                if ($typeHandle) {
                    $elementSources[] = "type:$typeHandle";
                }
            } else if ($element instanceof Category) {
                $groupHandle = $element->getGroup()?->handle;
                if ($groupHandle) {
                    $elementSources[] = "group:$groupHandle";
                }
            } else if ($element instanceof Asset) {
                $volumeHandle = $element->getVolume()?->handle;
                if ($volumeHandle) {
                    $elementSources[] = "volume:$volumeHandle";
                }
            } else if ($element instanceof User) {
                $elementSources[] = 'users';
            }
            foreach ($elementSourcesToPurge as $elementSourceKey => $urisToPurge) {
                $elementSourceKey = preg_replace('/\s+/', '', $elementSourceKey);
                if (empty($elementSourceKey) || !in_array($elementSourceKey, $elementSources, true)) {
                    continue;
                }
                $this->_stuffToPurge[$site->handle]['uris'] = [
                    ...($this->_stuffToPurge[$site->handle]['uris'] ?? []),
                    ...$urisToPurge,
                ];
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
            $uris = $stuff['uris'] ?? [];
            $elementIds = $stuff['elementIds'] ?? [];
            if (empty($uris) && empty($elementIds)) {
                continue;
            }
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle, true);
            $urisToPurge = CloudflareMateHelper::getUrisToPurgeFromSourceUrisAndIds($uris, $elementIds, $site->id);
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

}
