<?php

namespace vaersaagod\cloudflaremate\console\controllers;

use Craft;
use craft\console\Controller;

use vaersaagod\cloudflaremate\helpers\PurgeHelper;

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

    public function actionSite(?string $siteHandle = null): int
    {
        if (!empty($siteHandle)) {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                throw new \RuntimeException("Site with handle \"$siteHandle\" not found.");
            }
        } else {
            $site = Craft::$app->getSites()->getPrimarySite();
        }
        $this->stdout("Purging zone for site \"$site->handle\"..." . PHP_EOL, BaseConsole::FG_YELLOW);
        $result = PurgeHelper::site($site);
        if ($result) {
            $this->stdout("Big success!" . PHP_EOL, BaseConsole::FG_GREEN);
        } else {
            $this->stdout("Fail" . PHP_EOL, BaseConsole::FG_RED);
        }
        return ExitCode::OK;
    }

    public function actionUrl(string $url): int
    {
        $this->stdout("Purging URL \"$url\"..." . PHP_EOL, BaseConsole::FG_YELLOW);
        $result = PurgeHelper::url($url);
        if ($result) {
            $this->stdout("Big success!" . PHP_EOL, BaseConsole::FG_GREEN);
        } else {
            $this->stdout("Fail" . PHP_EOL, BaseConsole::FG_RED);
        }
        return ExitCode::OK;
    }
}
