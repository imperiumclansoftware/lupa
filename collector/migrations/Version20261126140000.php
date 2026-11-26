<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Distributions personnalisées (Réglages). */
final class Version20261126140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distributions personnalisées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_distribution (id BLOB NOT NULL, slug VARCHAR(32) NOT NULL, ecosystem VARCHAR(16) NOT NULL, label VARCHAR(120) NOT NULL, "release" VARCHAR(40) NOT NULL, codename VARCHAR(60) NOT NULL, eol BOOLEAN NOT NULL, architectures CLOB NOT NULL, sections CLOB NOT NULL, sources CLOB NOT NULL, signing_key CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DFCF209A989D9B62 ON custom_distribution (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE custom_distribution');
    }
}
