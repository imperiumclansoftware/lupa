<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015084245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE advisory (id VARCHAR(80) NOT NULL, modified VARCHAR(40) NOT NULL, data CLOB NOT NULL, fetched_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE artifact ADD COLUMN vulnerabilities CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact ADD COLUMN severity VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact ADD COLUMN audited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE advisory');
        $this->addSql('CREATE TEMPORARY TABLE __temp__artifact AS SELECT id, identity, ecosystem, name, version, filename, blobs, size, metadata, created_at, updated_at, export_id FROM artifact');
        $this->addSql('DROP TABLE artifact');
        $this->addSql('CREATE TABLE artifact (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, filename VARCHAR(255) DEFAULT NULL, blobs CLOB NOT NULL, size BIGINT NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, export_id INTEGER DEFAULT NULL, CONSTRAINT FK_48E5602C64CDAF82 FOREIGN KEY (export_id) REFERENCES export (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO artifact (id, identity, ecosystem, name, version, filename, blobs, size, metadata, created_at, updated_at, export_id) SELECT id, identity, ecosystem, name, version, filename, blobs, size, metadata, created_at, updated_at, export_id FROM __temp__artifact');
        $this->addSql('DROP TABLE __temp__artifact');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_48E5602C6A95E9C4 ON artifact (identity)');
        $this->addSql('CREATE INDEX IDX_48E5602CABE8FB395E237E06 ON artifact (ecosystem, name)');
        $this->addSql('CREATE INDEX IDX_48E5602C64CDAF82 ON artifact (export_id)');
    }
}
