<?php

namespace vaersaagod\cloudflaremate\migrations;

use Craft;
use craft\db\Migration;
use craft\records\Site;

use vaersaagod\cloudflaremate\records\UriRecord;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    private function createTables(): bool
    {
        $tablesCreated = false;

        // Tracker table (log)
        $trackerTableSchema = Craft::$app->db->schema->getTableSchema('{{%redirectmate_tracker}}');

        if ($trackerTableSchema === null) {
            $tablesCreated = true;
            $this->createTable(
                UriRecord::tableName(),
                [
                    'id' => $this->primaryKey(),
                    'uri' => $this->string(UriRecord::MAX_URI_LENGTH)->notNull(),
                    'siteId' => $this->integer()->notNull(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ]
            );
        }

        return $tablesCreated;
    }

    private function createIndexes(): void
    {
        $this->createIndex(
            null,
            UriRecord::tableName(),
            ['siteId', 'uri'],
            true
        );
    }

    protected function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            UriRecord::tableName(),
            'siteId',
            Site::tableName(),
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(UriRecord::tableName());
        return true;
    }
}
