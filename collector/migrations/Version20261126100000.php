<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rapport structuré d'une tâche (vérification d'intégrité du stockage) ; null pour les autres tâches. */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rapport des tâches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN report CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP COLUMN report');
    }
}
