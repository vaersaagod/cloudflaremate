<?php

namespace vaersaagod\cloudflaremate;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\BatchElementActionEvent;
use craft\events\ElementEvent;
use craft\helpers\App;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\web\Response;

use Psr\Log\LogLevel;

use vaersaagod\cloudflaremate\helpers\ResponseHelper;
use vaersaagod\cloudflaremate\models\Settings;
use vaersaagod\cloudflaremate\services\Purge;

use yii\base\Event;
use yii\web\Response as BaseResponse;

/**
 * CloudflareMate plugin
 *
 * @method static CloudflareMate getInstance()
 * @method Settings getSettings()
 * @property-read Purge $purge
 */
class CloudflareMate extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => ['purge' => Purge::class],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Custom log target
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'cloudflaremate',
            'categories' => ['cloudflaremate', "vaersaagod\\cloudflaremate\\*"],
            'extractExceptionTrace' => !App::devMode(),
            'allowLineBreaks' => App::devMode(),
            'level' => App::devMode() ? LogLevel::INFO : LogLevel::WARNING,
            'maxFiles' => 10,
            'logContext' => App::devMode(),
        ]);

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @return void
     * @throws \Throwable
     */
    private function attachEventHandlers(): void
    {

        Event::on(
            Response::class,
            BaseResponse::EVENT_BEFORE_SEND,
            function (Event $event) {
                if (!$event->sender instanceof Response) {
                    return;
                }
                ResponseHelper::prepare($event->sender);
            }
        );

        $elementsEvents = [
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            Elements::EVENT_AFTER_RESAVE_ELEMENT,
            Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
        ];
        foreach ($elementsEvents as $elementsEvent) {
            Event::on(
                Elements::class,
                $elementsEvent,
                static function (ElementEvent|BatchElementActionEvent $event) {
                    $element = $event->element;
                    CloudflareMate::getInstance()->purge->maybePurgeElement($element);
                }
            );
        }
    }
}
