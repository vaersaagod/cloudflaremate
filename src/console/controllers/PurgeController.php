<?php

namespace vaersaagod\cloudflaremate\console\controllers;

use craft\console\Controller;

use vaersaagod\cloudflaremate\CloudflareMate;
use vaersaagod\cloudflaremate\helpers\ApiHelper;
use vaersaagod\cloudflaremate\helpers\PurgeHelper;
use vaersaagod\cloudflaremate\helpers\SiteHelper;

use yii\console\ExitCode;
use yii\helpers\BaseConsole;

/**
 * Purge controller
 */
class PurgeController extends Controller
{
    public $defaultAction = 'index';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'index':
                // $options[] = '...';
                break;
        }
        return $options;
    }

    public function actionIndex(): int
    {
        $this->stdout('Hi mom!' . PHP_EOL);
        return ExitCode::OK;
    }

    public function actionUrl(string $url, string|int|null $site = null): int
    {
        $this->stdout("Purging URL \"$url\"..." . PHP_EOL, BaseConsole::FG_YELLOW);
        $result = PurgeHelper::purgeUri($url, $site);
        if ($result) {
            $this->stdout("Big success!" . PHP_EOL, BaseConsole::FG_GREEN);
        } else {
            $this->stdout("Fail" . PHP_EOL, BaseConsole::FG_RED);
        }
        return ExitCode::OK;
    }

    public function actionSite(string|int|null $site = null): int
    {
        $site = SiteHelper::getSiteFromParam($site);
        $this->stdout("Purging site \"$site->handle\"..." . PHP_EOL, BaseConsole::FG_YELLOW);
        $result = ApiHelper::purgeSite($site);
        if ($result) {
            $this->stdout("Big success!" . PHP_EOL, BaseConsole::FG_GREEN);
        } else {
            $this->stdout("Fail" . PHP_EOL, BaseConsole::FG_RED);
        }
        return ExitCode::OK;
    }

    public function actionZone(string $zoneId = null): int
    {
        $zoneId = $zoneId ?? CloudflareMate::getInstance()->getSettings()->defaultZone;
        if (empty($zoneId)) {
            throw new \Exception("No zone ID");
        }
        $result = ApiHelper::purgeZone($zoneId);
        if ($result) {
            $this->stdout("Big success!" . PHP_EOL, BaseConsole::FG_GREEN);
        } else {
            $this->stdout("Fail" . PHP_EOL, BaseConsole::FG_RED);
        }
        return ExitCode::OK;
    }
}
