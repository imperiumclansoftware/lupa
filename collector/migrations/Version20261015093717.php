<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015093717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liste noire, réglages et dépôts sources privés';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blacklist_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(128) NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_752724CB6A95E9C4 ON blacklist_entry (identity)');
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value CLOB NOT NULL, PRIMARY KEY (name))');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ecosystem VARCHAR(16) NOT NULL, label VARCHAR(120) NOT NULL, url VARCHAR(500) NOT NULL, scope VARCHAR(120) DEFAULT NULL, auth_type VARCHAR(8) NOT NULL, username VARCHAR(255) DEFAULT NULL, secret VARCHAR(2000) DEFAULT NULL, replaces_default BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE blacklist_entry');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE source');
    }
}
