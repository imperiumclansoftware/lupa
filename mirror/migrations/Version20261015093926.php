<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015093926 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quarantaine (liste noire des collecteurs) et réglages';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quarantine_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, collector_id VARCHAR(64) NOT NULL, collector_name VARCHAR(120) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(128) NOT NULL, reason VARCHAR(500) NOT NULL, revision INTEGER NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX IDX_D11724AA670BAFFE ON quarantine_entry (collector_id)');
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value CLOB NOT NULL, PRIMARY KEY (name))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE quarantine_entry');
        $this->addSql('DROP TABLE setting');
    }
}
