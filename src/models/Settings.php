<?php

namespace vaersaagod\cloudflaremate\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * CloudflareMate settings
 */
class Settings extends Model
{

    /** @var string|null The Cache-Control header set for the initial request */
    public ?string $defaultCacheControlHeader = 'no-store';

    /** @var string The Cache-Control header set for the request if/when CFM figures it can be cached */
    public string $cacheControlHeader = 'public, max-age=31536000'; // TODO validation. This is required

    /** @var string A Cloudflare API token (not key!) */
    public string $apiToken;

    /** @var array An array of key => value pairs, where the keys are site handles and the values are Cloudflare zone IDs */
    public array $zoneIds;

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'apiToken',
            ],
        ];
        return $behaviors;
    }

    public function rules(): array
    {
        return [
            [['apiToken', 'zoneIds'], 'required'],
        ];
    }

}
