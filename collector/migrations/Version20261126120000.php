<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Dépôts apt et dnf tiers : distribution de rattachement, suite et composants (apt), clé de signature. */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dépôts système tiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source ADD COLUMN distribution VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN suite VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN components CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN signing_key CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source DROP COLUMN signing_key');
        $this->addSql('ALTER TABLE source DROP COLUMN components');
        $this->addSql('ALTER TABLE source DROP COLUMN suite');
        $this->addSql('ALTER TABLE source DROP COLUMN distribution');
    }
}
