<?php

namespace vaersaagod\cloudflaremate\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;

use vaersaagod\cloudflaremate\CloudflareMate;
use vaersaagod\cloudflaremate\records\UriRecord;

final class CloudflareMateHelper
{

    /**
     * Given an array of element IDs, returns an array of URIs from all elements related to those URIs
     *
     * @param int|array $elementIds
     * @param int $siteId
     * @return array
     */
    public static function getUrisFromElementRelations(int|array $elementIds, int $siteId): array
    {
        if (!is_array($elementIds)) {
            $elementIds = [$elementIds];
        }
        if (empty($elementIds)) {
            return [];
        }
        $uris = [];
        // Let's batch this, because queries can fail if they get too large
        $elementIdsBatched = array_chunk(array_values(array_unique($elementIds)), 500);
        foreach ($elementIdsBatched as $elementIdsBatch) {
            $baseRelationUrisQuery = (new Query())
                ->select('elements_sites.uri')
                ->from(Table::ELEMENTS_SITES . ' AS elements_sites')
                ->innerJoin(Table::ELEMENTS . ' AS elements', 'elements.id = elements_sites.elementId')
                ->where('elements_sites.uri IS NOT NULL')
                ->andWhere(['elements_sites.siteId' => $siteId])
                ->andWhere('elements.revisionId IS NULL')
                ->andWhere('elements.draftId IS NULL')
                ->andWhere('elements.dateDeleted IS NULL')
                ->andWhere(['elements.archived' => false])
                ->andWhere([
                    'OR',
                    ['IN', 'relations.sourceId', $elementIdsBatch],
                    ['IN', 'relations.targetId', $elementIdsBatch],
                ])
                ->distinct();

            // URIs for direct relations (source or target)
            $directRelationUris = (clone $baseRelationUrisQuery)
                ->innerJoin(Table::RELATIONS . ' AS relations', 'relations.targetId = elements_sites.elementId OR relations.sourceId = elements_sites.elementId')
                ->column();

            // URIs for relations inside Matrix blocks (source only; Matrix blocks are never the target of relations)
            $matrixRelationUris = (clone $baseRelationUrisQuery)
                ->innerJoin(Table::MATRIXBLOCKS_OWNERS . ' AS matrixblocks_owners', 'matrixblocks_owners.ownerId = elements_sites.elementId')
                ->innerJoin(Table::RELATIONS . ' AS relations', 'relations.sourceId = matrixblocks_owners.blockId')
                ->column();

            // URIs for relations inside SuperTable blocks (source only; SuperTable blocks are never the target of relations)
            $superTableRelationUris = (clone $baseRelationUrisQuery)
                ->innerJoin('{{%supertableblocks_owners}} AS supertableblocks_owners', 'supertableblocks_owners.ownerId = elements_sites.elementId')
                ->innerJoin(Table::RELATIONS . ' AS relations', 'relations.sourceId = supertableblocks_owners.blockId')
                ->column();

            // URIs for relations inside SuperTable blocks inside Matrix blocks (source only; SuperTable blocks are never the target of relations)
            $superTableInMatrixRelationUris = (clone $baseRelationUrisQuery)
                ->innerJoin(Table::MATRIXBLOCKS_OWNERS . ' AS matrixblocks_owners', 'matrixblocks_owners.ownerId = elements_sites.elementId')
                ->innerJoin('{{%supertableblocks_owners}} AS supertableblocks_owners', 'supertableblocks_owners.ownerId = matrixblocks_owners.blockId')
                ->innerJoin(Table::RELATIONS . ' AS relations', 'relations.sourceId = supertableblocks_owners.blockId')
                ->column();

            // ...and that's where we stop going down the rabbit hole. If you're putting Matrix blocks inside SuperTable blocks inside Matrix blocks, you've already lost.
            // I can't wait to refactor this for Craft 5 😬

            $uris = [
                ...$uris,
                ...[
                    ...$directRelationUris,
                    ...$matrixRelationUris,
                    ...$superTableRelationUris,
                    ...$superTableInMatrixRelationUris,
                ],
            ];
        }
        return array_values(array_unique($uris));
    }

    /**
     * Given an array of URIs, returns an array of matching URIs as per the `additionalUrisToPurge` config setting
     *
     * @param string|array $sourceUris The URIs to match (typically; URIs being purged)
     * @return array
     */
    public static function getAdditionalUrisToPurge(string|array $sourceUris): array
    {
        if (!is_array($sourceUris)) {
            $sourceUris = [$sourceUris];
        }
        if (empty($sourceUris)) {
            return [];
        }
        $settings = CloudflareMate::getInstance()->getSettings();
        $uris = [];
        foreach ($sourceUris as $sourceUri) {
            if (UrlHelper::isAbsoluteUrl($sourceUri)) {
                continue;
            }
            foreach ($settings->additionalUrisToPurge as $uriPattern => $uriPatternUris) {
                $uriPattern = trim(preg_replace('/\s+/', '', $uriPattern), '/');
                if (empty($uriPattern)) {
                    continue;
                }
                if (!is_array($uriPatternUris)) {
                    $uriPatternUris = explode(',', preg_replace('/\s+/', '', $uriPatternUris));
                }
                $uriPatternUris = array_values(array_filter($uriPatternUris));
                if (empty($uriPatternUris)) {
                    continue;
                }
                try {
                    if (!preg_match("/$uriPattern/", $sourceUri)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    Craft::error("Invalid regex pattern \"$uriPattern\"", __METHOD__);
                    Craft::error($e, __METHOD__);
                    continue;
                }
                foreach ($uriPatternUris as $uriPatternUri) {
                    $uriPatternUri = preg_replace('/\s+/', '', $uriPatternUri);
                    if (str_contains($uriPatternUri, '$')) {
                        $uriPatternUri = preg_replace("/$uriPattern/", $uriPatternUri, $sourceUri);
                    }
                    $uriPatternUri = trim($uriPatternUri, '/');
                    $uris[] = $uriPatternUri;
                }
            }
        }
        return $uris;
    }

    /**
     * @param string|array $uriPrefixes
     * @param int $siteId
     * @return array
     */
    public static function getLoggedUrisByPrefix(string|array $uriPrefixes, int $siteId): array
    {
        if (!is_array($uriPrefixes)) {
            $uriPrefixes = [$uriPrefixes];
        }
        if (empty($uriPrefixes)) {
            return [];
        }

        $query = UriRecord::find()
            ->select('uri')
            ->where(['siteId' => $siteId]);

        $andWhereStatement = [];

        foreach ($uriPrefixes as $uriPrefix) {

            $uriPrefix = rtrim($uriPrefix, '/');

            if (empty($uriPrefix) || $uriPrefix === '__home__') {
                $andWhereStatement[] = [
                    'OR',
                    "uri IS NULL",
                    "uri = ''",
                    "uri = '/'",
                    "uri LIKE '?%'",
                    "uri LIKE '/?%'",
                ];
            } else {
                $andWhereStatement[] = "uri LIKE '$uriPrefix%'";
            }
        }

        return $query
            ->andWhere([
                'OR',
                ...$andWhereStatement,
            ])
            ->distinct()
            ->column();
    }

    /**
     * @param string $uri
     * @return bool
     */
    public static function shouldUriBeIgnored(string $uri): bool
    {
        $settings = CloudflareMate::getInstance()->getSettings();
        if (empty($settings->ignoredUris)) {
            return false;
        }
        foreach ($settings->ignoredUris as $uriPattern) {
            $uriPattern = trim(preg_replace('/\s+/', '', $uriPattern), '/');
            if (empty($uriPattern)) {
                continue;
            }
            try {
                if (preg_match("/$uriPattern/", $uri)) {
                    return true;
                }
            } catch (\Throwable $e) {
                Craft::error("Invalid regex pattern \"$uriPattern\"", __METHOD__);
                Craft::error($e, __METHOD__);
            }
        }
        return false;
    }

    /**
     * @param array $uris
     * @param array $elementIds
     * @param int $siteId
     * @return array
     */
    public static function getUrisToPurgeFromSourceUrisAndIds(array $uris, array $elementIds, int $siteId): array
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
