<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration200000001 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 200000001;
    }

    public function update(Connection $connection): void
    {
        // Prüfe, ob die Spalten bereits existieren
        $columns = $connection->fetchAllAssociative(
            "SHOW COLUMNS FROM `plugin_git` LIKE 'installed_branch'"
        );
        
        if (empty($columns)) {
            // Füge neue Spalten hinzu
            $connection->executeStatement('
                ALTER TABLE `plugin_git` 
                ADD COLUMN `installed_branch` VARCHAR(255) NULL AFTER `github_url`,
                ADD COLUMN `installed_commit` VARCHAR(255) NULL AFTER `installed_branch`,
                ADD COLUMN `plugin_version` VARCHAR(255) NULL AFTER `installed_commit`
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}