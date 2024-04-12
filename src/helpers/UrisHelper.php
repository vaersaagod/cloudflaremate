<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\helpers\Db;
use craft\web\Request;

use vaersaagod\cloudflaremate\models\UriModel;
use vaersaagod\cloudflaremate\records\UriRecord;

final class UrisHelper
{

    /**
     * @param Request $request
     * @return UriModel|null
     * @throws \Exception
     */
    public static function getUriModelFromRequest(Request $request): ?UriModel
    {
        try {
            $site = $request->sites->getCurrentSite();
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            return null;
        }

        $uri = UrlHelper::getUriFromFullUrl($request->getAbsoluteUrl(), $site);

        return new UriModel([
            'uri' => $uri,
            'siteId' => $site->id,
        ]);
    }

    /**
     * @param UriModel $uriModel
     * @return bool
     */
    public static function insertOrUpdateUriRecord(UriModel $uriModel): bool
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
     * @param array $uris
     * @param int $siteId
     * @return void
     * @throws \yii\db\Exception
     */
    public static function deleteUris(array $uris, int $siteId): void
    {
        if (empty($uris)) {
            return;
        }
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(
            UriRecord::tableName(),
            [
                'AND',
                ['in', 'uri', $uris],
                ['=', 'siteId', $siteId],
            ]
        )->execute();
    }

    /**
     * @param int $siteId
     * @return void
     * @throws \yii\db\Exception
     */
    public static function deleteAllUrisForSite(int $siteId): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(
            UriRecord::tableName(),
            ['siteId' => $siteId]
        )->execute();
    }

    /**
     * @return void
     * @throws \yii\db\Exception
     */
    public static function deleteAllUris(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(
            UriRecord::tableName()
        )->execute();
    }

}
