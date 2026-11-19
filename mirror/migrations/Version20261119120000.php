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
        'trusted_key' => 'CREATE TABLE trusted_key (id BLOB NOT NULL, public_key VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))',
        'quarantine_entry' => 'CREATE TABLE quarantine_entry (id BLOB NOT NULL, collector_id VARCHAR(64) NOT NULL, collector_name VARCHAR(120) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(128) NOT NULL, reason VARCHAR(500) NOT NULL, revision INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))',
        'package' => 'CREATE TABLE package (id BLOB NOT NULL, identity VARCHAR(40) NOT NULL, ecosystem VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, version VARCHAR(255) NOT NULL, filename VARCHAR(255) DEFAULT NULL, blobs CLOB NOT NULL, size BIGINT NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, bundle_id BLOB DEFAULT NULL, PRIMARY KEY (id), FOREIGN KEY (bundle_id) REFERENCES imported_bundle (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'user' => 'CREATE TABLE "user" (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY (id))',
        'imported_bundle' => 'CREATE TABLE imported_bundle (id BLOB NOT NULL, uuid VARCHAR(36) NOT NULL, collector_id VARCHAR(64) NOT NULL, collector_name VARCHAR(120) NOT NULL, sequence INTEGER NOT NULL, "full" BOOLEAN NOT NULL, source VARCHAR(255) NOT NULL, artifact_count INTEGER NOT NULL, new_blob_count INTEGER NOT NULL, size BIGINT NOT NULL, summary CLOB NOT NULL, created_at DATETIME NOT NULL, imported_at DATETIME NOT NULL, PRIMARY KEY (id))',
        'job' => 'CREATE TABLE job (id BLOB NOT NULL, type VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, progress INTEGER DEFAULT NULL, step VARCHAR(255) NOT NULL, log CLOB NOT NULL, error CLOB DEFAULT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, PRIMARY KEY (id))',
    ];

    private const array INDEXES = [
        'CREATE UNIQUE INDEX UNIQ_853803C266F9D463 ON trusted_key (public_key)',
        'CREATE INDEX IDX_D11724AA670BAFFE ON quarantine_entry (collector_id)',
        'CREATE UNIQUE INDEX UNIQ_DE6867956A95E9C4 ON package (identity)',
        'CREATE INDEX IDX_DE686795ABE8FB395E237E06 ON package (ecosystem, name)',
        'CREATE INDEX IDX_DE686795F1FAD9D3 ON package (bundle_id)',
        'CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)',
        'CREATE UNIQUE INDEX UNIQ_E2178F9ED17F50A6 ON imported_bundle (uuid)',
        'CREATE INDEX IDX_E2178F9E670BAFFE5286D72B ON imported_bundle (collector_id, sequence)',
        'CREATE INDEX IDX_FBD8E0F87B00651C ON job (status)',
    ];

    private const array REKEYED = ['trusted_key', 'quarantine_entry', 'package', 'user', 'imported_bundle', 'job'];

    private const array FOREIGN_KEYS = [
        'package.bundle_id' => 'imported_bundle',
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
