<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\Response;

use vaersaagod\cloudflaremate\CloudflareMate;

final class ResponseHelper
{

    /**
     * @param Response $response
     * @return void
     * @throws \Throwable
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\web\BadRequestHttpException
     */
    public static function prepare(Response $response): void
    {

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        $settings = CloudflareMate::getInstance()->getSettings();

        // Set default cache control header (should just use the native no-cache header instead?
        if (!empty($settings->defaultCacheControlHeader)) {
            $response->getHeaders()->set('Cache-Control', $settings->defaultCacheControlHeader);
        }

        // Is the request cache-able?
        if (
            !$request->getIsSiteRequest() ||
            !$request->getIsGet() ||
            $request->getIsPreview() ||
            $request->getToken() !== null ||
            $request->getIsActionRequest() ||
            $request->getIsLoginRequest() ||
            $request->getParam('no-cache') ||
            !$response->getIsOk() ||
            Craft::$app->getUser()->getIdentity()?->getPreference('enableDebugToolbarForSite') ||
            !empty(Craft::$app->getSession()->getAllFlashes())
        ) {
            return;
        }

        // Get the URI for this request
        $uriModel = UriHelper::getUriFromRequest($request);
        if (empty($uriModel)) {
            return;
        }

        // Make sure it's not a resource URI
        $resourceBaseUri = trim(parse_url(App::parseEnv(Craft::$app->getConfig()->getGeneral()->resourceBaseUrl), PHP_URL_PATH) ?? '', '/');
        if (!empty($resourceBaseUri) && str_starts_with($uriModel->uri, $resourceBaseUri)) {
            return;
        }

        // Avoid index.php
        if (str_contains($uriModel->uri, 'index.php')) {
            return;
        }

        // Validate the URI
        if (!$uriModel->validate()) {
            Craft::error("Invalid URI: " . Json::errorSummary($uriModel), __METHOD__);
            return;
        }

        // Try logging the URI to the DB
        try {
            if (!UriHelper::insertOrUpdateUri($uriModel)) {
                return;
            }
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return;
        }

        $response->getHeaders()->set('Cache-Control', $settings->cacheControlHeader);

        // https://developers.cloudflare.com/cache/about/default-cache-behavior
        $response->getCookies()->removeAll();
    }

}
