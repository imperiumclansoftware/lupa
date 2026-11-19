<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Sas\Bundle\Doctrine\UuidRekey;

/**
 * Identifiants UUID v7 à la place des entiers auto-incrémentés (ADR 0006). Tables recréées et
 * données recopiées, clés étrangères traduites ; les tâches en attente sont abandonnées.
 */
final class Version20261119120000 extends AbstractMigration
{
    private const array TABLES = [
        'user' => 'CREATE TABLE "user" (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY (id))',
        'artifact' => 'CREATE TABLE artifact (id BLOB NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, filename VARCHAR(255) DEFAULT NULL, blobs CLOB NOT NULL, size BIGINT NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, vulnerabilities CLOB DEFAULT NULL, severity VARCHAR(16) DEFAULT NULL, audited_at DATETIME DEFAULT NULL, export_id BLOB DEFAULT NULL, PRIMARY KEY (id), FOREIGN KEY (export_id) REFERENCES export (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'blob' => 'CREATE TABLE blob (sha256 VARCHAR(64) NOT NULL, size BIGINT NOT NULL, created_at DATETIME NOT NULL, export_id BLOB DEFAULT NULL, PRIMARY KEY (sha256), FOREIGN KEY (export_id) REFERENCES export (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'source' => 'CREATE TABLE source (id BLOB NOT NULL, ecosystem VARCHAR(16) NOT NULL, label VARCHAR(120) NOT NULL, url VARCHAR(500) NOT NULL, scope VARCHAR(120) DEFAULT NULL, auth_type VARCHAR(8) NOT NULL, username VARCHAR(255) DEFAULT NULL, secret VARCHAR(2000) DEFAULT NULL, replaces_default BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))',
        'blacklist_entry' => 'CREATE TABLE blacklist_entry (id BLOB NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(128) NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))',
        'package_list' => 'CREATE TABLE package_list (id BLOB NOT NULL, name VARCHAR(120) NOT NULL, ecosystem VARCHAR(16) NOT NULL, specs CLOB NOT NULL, lockfile_name VARCHAR(255) DEFAULT NULL, lockfile CLOB DEFAULT NULL, options CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, PRIMARY KEY (id))',
        'package_list_artifact' => 'CREATE TABLE package_list_artifact (package_list_id BLOB NOT NULL, artifact_id BLOB NOT NULL, PRIMARY KEY (package_list_id, artifact_id), FOREIGN KEY (package_list_id) REFERENCES package_list (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (artifact_id) REFERENCES artifact (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'export' => 'CREATE TABLE export (id BLOB NOT NULL, sequence INTEGER NOT NULL, uuid VARCHAR(36) NOT NULL, "full" BOOLEAN NOT NULL, max_part_size BIGINT DEFAULT NULL, base_name VARCHAR(120) NOT NULL, files CLOB NOT NULL, size BIGINT NOT NULL, artifact_count INTEGER NOT NULL, blob_count INTEGER NOT NULL, summary CLOB NOT NULL, created_at DATETIME NOT NULL, job_id BLOB DEFAULT NULL, PRIMARY KEY (id), FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'job' => 'CREATE TABLE job (id BLOB NOT NULL, type VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, progress INTEGER DEFAULT NULL, step VARCHAR(255) NOT NULL, log CLOB NOT NULL, error CLOB DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, list_id BLOB DEFAULT NULL, PRIMARY KEY (id), FOREIGN KEY (list_id) REFERENCES package_list (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
    ];

    private const array INDEXES = [
        'CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)',
        'CREATE UNIQUE INDEX UNIQ_48E5602C6A95E9C4 ON artifact (identity)',
        'CREATE INDEX IDX_48E5602CABE8FB395E237E06 ON artifact (ecosystem, name)',
        'CREATE INDEX IDX_48E5602C64CDAF82 ON artifact (export_id)',
        'CREATE INDEX IDX_B07FA5CC64CDAF82 ON blob (export_id)',
        'CREATE UNIQUE INDEX UNIQ_752724CB6A95E9C4 ON blacklist_entry (identity)',
        'CREATE INDEX IDX_F84DABCCB2F57307 ON package_list_artifact (package_list_id)',
        'CREATE INDEX IDX_F84DABCCE28B07AC ON package_list_artifact (artifact_id)',
        'CREATE UNIQUE INDEX UNIQ_428C16945286D72B ON export (sequence)',
        'CREATE UNIQUE INDEX UNIQ_428C1694BE04EA9 ON export (job_id)',
        'CREATE INDEX IDX_FBD8E0F87B00651C ON job (status)',
        'CREATE INDEX IDX_FBD8E0F83DAE168B ON job (list_id)',
    ];

    private const array REKEYED = ['user', 'artifact', 'source', 'blacklist_entry', 'package_list', 'export', 'job'];

    private const array FOREIGN_KEYS = [
        'artifact.export_id' => 'export',
        'blob.export_id' => 'export',
        'package_list_artifact.package_list_id' => 'package_list',
        'package_list_artifact.artifact_id' => 'artifact',
        'export.job_id' => 'job',
        'job.list_id' => 'package_list',
    ];

    public function getDescription(): string
    {
        return 'Identifiants UUID v7';
    }

    public function isTransactional(): bool
    {
        // UuidRekey gère sa transaction : PRAGMA foreign_keys doit être réglé en dehors
        return false;
    }

    public function up(Schema $schema): void
    {
        // Messages de tâches en attente : ils portent l'ancien identifiant entier
        $this->connection->executeStatement("UPDATE job SET status = 'failed', error = 'Interrompue par la mise à jour.' WHERE status IN ('queued', 'running')");
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        UuidRekey::run($this->connection, self::TABLES, self::INDEXES, self::REKEYED, self::FOREIGN_KEYS);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Retour aux identifiants entiers impossible : restaurer la sauvegarde.');
    }
}
