<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Visites guidées déjà vues par chaque compte. */
final class Version20261126160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visites guidées vues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD COLUMN seen_tours CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN seen_tours');
    }
}
