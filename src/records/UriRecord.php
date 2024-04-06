<?php

namespace vaersaagod\cloudflaremate\records;

use craft\db\ActiveRecord;

/**
 * @property string $uri
 * @property int $siteId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class UriRecord extends ActiveRecord
{

    public const MAX_URI_LENGTH = 255;

    public static function tableName()
    {
        return '{{%cloudflaremate_uris}}';
    }
}
