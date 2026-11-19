<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Rôles (ADR 0010) et double authentification facultative (ADR 0009) ; les comptes existants restent administrateurs. */
final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rôles et double authentification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"user\" ADD COLUMN role VARCHAR(16) DEFAULT 'admin' NOT NULL");
        $this->addSql('ALTER TABLE "user" ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL');
        $this->addSql("ALTER TABLE \"user\" ADD COLUMN backup_codes CLOB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN backup_codes');
        $this->addSql('ALTER TABLE "user" DROP COLUMN totp_secret');
        $this->addSql('ALTER TABLE "user" DROP COLUMN role');
    }
}
