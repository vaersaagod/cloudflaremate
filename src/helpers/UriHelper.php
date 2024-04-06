<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\helpers\Db;
use craft\web\Request;

use vaersaagod\cloudflaremate\models\UriModel;
use vaersaagod\cloudflaremate\records\UriRecord;

final class UriHelper
{

    /**
     * @param Request $request
     * @return UriModel|null
     */
    public static function getUriFromRequest(Request $request): ?UriModel
    {

        try {
            $site = $request->sites->getCurrentSite();
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return null;
        }

        $basePath = UriHelper::normalizeSlashes(parse_url($site->getBaseUrl() ?? '', PHP_URL_PATH) ?: '');
        $uri = $request->getFullUri();

        if (!empty($basePath) && str_starts_with("$uri/", "$basePath/")) {
            $uri = ltrim(substr($uri, strlen($basePath)), '/');
        }

        $queryString = $request->getQueryString();
        if (!empty($queryString)) {
            $uri = $uri . '?' . $queryString;
        }

        return new UriModel([
            'uri' => $uri,
            'siteId' => $site->id,
        ]);

    }

    /**
     * @param UriModel $uriModel
     * @return bool
     */
    public static function insertOrUpdateUri(UriModel $uriModel): bool
    {
        $uriRecord = UriRecord::find()
            ->where(['uri' => $uriModel->uri])
            ->andWhere(['siteId' => $uriModel->siteId])
            ->one() ?? new UriRecord();
        $uriRecord->uri = $uriModel->uri;
        $uriRecord->siteId = $uriModel->siteId;
        $uriRecord->dateUpdated = Db::prepareDateForDb(new \DateTime());
        return $uriRecord->save();
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalizeSlashes(string $path): string
    {
        return preg_replace('/\/\/+/', '/', trim($path, '/'));
    }

}
