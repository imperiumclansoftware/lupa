<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008102900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE imported_bundle (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, uuid VARCHAR(36) NOT NULL, collector_id VARCHAR(64) NOT NULL, collector_name VARCHAR(120) NOT NULL, sequence INTEGER NOT NULL, "full" BOOLEAN NOT NULL, source VARCHAR(255) NOT NULL, artifact_count INTEGER NOT NULL, new_blob_count INTEGER NOT NULL, size BIGINT NOT NULL, summary CLOB NOT NULL, created_at DATETIME NOT NULL, imported_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2178F9ED17F50A6 ON imported_bundle (uuid)');
        $this->addSql('CREATE INDEX IDX_E2178F9E670BAFFE5286D72B ON imported_bundle (collector_id, sequence)');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, progress INTEGER DEFAULT NULL, step VARCHAR(255) NOT NULL, log CLOB NOT NULL, error CLOB DEFAULT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_FBD8E0F87B00651C ON job (status)');
        $this->addSql('CREATE TABLE oci_manifest (digest VARCHAR(71) NOT NULL, media_type VARCHAR(120) NOT NULL, PRIMARY KEY (digest))');
        $this->addSql('CREATE TABLE package (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, filename VARCHAR(255) DEFAULT NULL, blobs CLOB NOT NULL, size BIGINT NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, bundle_id INTEGER DEFAULT NULL, CONSTRAINT FK_DE686795F1FAD9D3 FOREIGN KEY (bundle_id) REFERENCES imported_bundle (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DE6867956A95E9C4 ON package (identity)');
        $this->addSql('CREATE INDEX IDX_DE686795ABE8FB395E237E06 ON package (ecosystem, name)');
        $this->addSql('CREATE INDEX IDX_DE686795F1FAD9D3 ON package (bundle_id)');
        $this->addSql('CREATE TABLE trusted_key (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_key VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_853803C266F9D463 ON trusted_key (public_key)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE imported_bundle');
        $this->addSql('DROP TABLE job');
        $this->addSql('DROP TABLE oci_manifest');
        $this->addSql('DROP TABLE package');
        $this->addSql('DROP TABLE trusted_key');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
