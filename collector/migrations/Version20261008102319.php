<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008102319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE artifact (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, filename VARCHAR(255) DEFAULT NULL, blobs CLOB NOT NULL, size BIGINT NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, export_id INTEGER DEFAULT NULL, CONSTRAINT FK_48E5602C64CDAF82 FOREIGN KEY (export_id) REFERENCES export (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_48E5602C6A95E9C4 ON artifact (identity)');
        $this->addSql('CREATE INDEX IDX_48E5602CABE8FB395E237E06 ON artifact (ecosystem, name)');
        $this->addSql('CREATE INDEX IDX_48E5602C64CDAF82 ON artifact (export_id)');
        $this->addSql('CREATE TABLE blob (sha256 VARCHAR(64) NOT NULL, size BIGINT NOT NULL, created_at DATETIME NOT NULL, export_id INTEGER DEFAULT NULL, PRIMARY KEY (sha256), CONSTRAINT FK_B07FA5CC64CDAF82 FOREIGN KEY (export_id) REFERENCES export (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B07FA5CC64CDAF82 ON blob (export_id)');
        $this->addSql('CREATE TABLE export (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sequence INTEGER NOT NULL, uuid VARCHAR(36) NOT NULL, "full" BOOLEAN NOT NULL, max_part_size BIGINT DEFAULT NULL, base_name VARCHAR(120) NOT NULL, files CLOB NOT NULL, size BIGINT NOT NULL, artifact_count INTEGER NOT NULL, blob_count INTEGER NOT NULL, summary CLOB NOT NULL, created_at DATETIME NOT NULL, job_id INTEGER DEFAULT NULL, CONSTRAINT FK_428C1694BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_428C16945286D72B ON export (sequence)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_428C1694BE04EA9 ON export (job_id)');
        $this->addSql('CREATE TABLE job (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, progress INTEGER DEFAULT NULL, step VARCHAR(255) NOT NULL, log CLOB NOT NULL, error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, list_id INTEGER DEFAULT NULL, CONSTRAINT FK_FBD8E0F83DAE168B FOREIGN KEY (list_id) REFERENCES package_list (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FBD8E0F87B00651C ON job (status)');
        $this->addSql('CREATE INDEX IDX_FBD8E0F83DAE168B ON job (list_id)');
        $this->addSql('CREATE TABLE package_list (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, ecosystem VARCHAR(16) NOT NULL, specs CLOB NOT NULL, lockfile_name VARCHAR(255) DEFAULT NULL, lockfile CLOB DEFAULT NULL, options CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE TABLE package_list_artifact (package_list_id INTEGER NOT NULL, artifact_id INTEGER NOT NULL, PRIMARY KEY (package_list_id, artifact_id), CONSTRAINT FK_F84DABCCB2F57307 FOREIGN KEY (package_list_id) REFERENCES package_list (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F84DABCCE28B07AC FOREIGN KEY (artifact_id) REFERENCES artifact (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F84DABCCB2F57307 ON package_list_artifact (package_list_id)');
        $this->addSql('CREATE INDEX IDX_F84DABCCE28B07AC ON package_list_artifact (artifact_id)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE artifact');
        $this->addSql('DROP TABLE blob');
        $this->addSql('DROP TABLE export');
        $this->addSql('DROP TABLE job');
        $this->addSql('DROP TABLE package_list');
        $this->addSql('DROP TABLE package_list_artifact');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
