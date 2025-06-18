<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration20250426000100 extends MigrationStep
{
    public function getCreationTimestamp (): int
    {
        return 20250426000100;
    }

    public function update (Connection $connection): void
    {
        $connection->executeStatement('
    CREATE TABLE `plugin_git` (
        `id` BINARY(16) NOT NULL,
        `plugin_id` BINARY(16) NOT NULL,
        `source` VARCHAR(255) NOT NULL DEFAULT \'shopware\',
        `github_url` VARCHAR(255) NOT NULL,
        `created_at` DATETIME(3) NOT NULL,
        `updated_at` DATETIME(3) DEFAULT NULL,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk.extension_git.plugin_id` FOREIGN KEY (`plugin_id`)
            REFERENCES `plugin` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
');

    }

    public function updateDestructive (Connection $connection): void
    {
        // No destructive changes
    }
}
