<?php

namespace vaersaagod\cloudflaremate\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;

use vaersaagod\cloudflaremate\helpers\CloudflareMateHelper;
use vaersaagod\cloudflaremate\helpers\PurgeHelper;

use vaersaagod\cloudflaremate\helpers\UrisHelper;
use vaersaagod\cloudflaremate\helpers\UrlHelper;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Cp controller
 */
class CpController extends Controller
{

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function actionPurgeSite(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $siteHandle = $this->request->getRequiredBodyParam('site');
        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle, true);
        if (!$site) {
            throw new BadRequestHttpException("Invalid site handle: $siteHandle");
        }

        PurgeHelper::purgeSite($site);

        return $this->asSuccess(
            message: "Zone purged for site \"$site->name\""
        );

    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function actionPurgeUri(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $siteHandle = $this->request->getRequiredBodyParam('site');
        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle, true);
        if (!$site) {
            throw new BadRequestHttpException("Invalid site handle: $siteHandle");
        }

        $uri = $this->request->getRequiredBodyParam('uri');
        $uris = CloudflareMateHelper::getUrisToPurgeFromSourceUrisAndIds([$uri], [], $site->id);

        Craft::info("Purge URIs: " . Json::encode($uris), __METHOD__);

        if (empty($uris)) {
            return $this->asFailure(
                message: 'Nothing to purge'
            );
        }

        if (!PurgeHelper::purgeUris($uris)) {
            return $this->asFailure(
                message: "Failed to purge URI for site \"$site->name\""
            );
        }

        return $this->asSuccess(
            message: "URI purged for site \"$site->name\""
        );

    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function actionPurgeElement(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        if (!$site) {
            throw new BadRequestHttpException("Invalid site ID: $siteId");
        }

        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId, null, $site->id);
        if (!$element) {
            throw new BadRequestHttpException("Invalid element ID: $elementId");
        }

        $uris = [];

        if ($element::hasUris()) {
            $uris[] = UrlHelper::getUriFromFullUrl($element->getUrl(), $site);
        }

        $uris = CloudflareMateHelper::getUrisToPurgeFromSourceUrisAndIds($uris, [$element->id], $site->id);

        Craft::info("Purge URIs for element: " . Json::encode($uris), __METHOD__);

        if (empty($uris)) {
            return $this->asFailure(
                message: 'Nothing to purge'
            );
        }

        if (!PurgeHelper::purgeUris($uris)) {
            return $this->asFailure(
                message: "Failed to purge element for site \"$site->name\""
            );
        }

        return $this->asSuccess(
            message: "Element purged for site \"$site->name\""
        );

    }

}
