<?php

namespace Sas\Bundle\Doctrine;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Passage des clés entières aux UUID v7 sous SQLite (migrations de Rémus et Romulus).
 *
 * SQLite ne sait pas changer le type d'une clé primaire : chaque table est recréée avec son nouveau
 * schéma, puis remplie depuis l'ancienne en traduisant clés et clés étrangères par une table de
 * correspondance (ancien entier → UUID v7, générés dans l'ordre des anciens identifiants).
 */
final class UuidRekey
{
    /**
     * @param array<string, string> $tables      table => CREATE TABLE du nouveau schéma (sans index)
     * @param list<string>          $indexes     CREATE INDEX du nouveau schéma
     * @param list<string>          $rekeyed     tables dont la colonne id devient un UUID
     * @param array<string, string> $foreignKeys "table.colonne" => table référencée
     */
    public static function run(Connection $db, array $tables, array $indexes, array $rekeyed, array $foreignKeys): void
    {
        // Hors transaction : SQLite ignore ce PRAGMA dans une transaction ouverte
        $db->executeStatement('PRAGMA foreign_keys = OFF');
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE TEMP TABLE _uuid_map (tbl TEXT NOT NULL, old INTEGER NOT NULL, new BLOB NOT NULL, PRIMARY KEY (tbl, old))');
            foreach ($rekeyed as $table) {
                /** @var list<int> $ids */
                $ids = $db->fetchFirstColumn(\sprintf('SELECT id FROM %s ORDER BY id', self::quote($table)));
                foreach ($ids as $old) {
                    $db->insert('_uuid_map', ['tbl' => $table, 'old' => $old, 'new' => Uuid::v7()->toBinary()], ['tbl' => 'string', 'old' => 'integer', 'new' => 'binary']);
                }
            }

            foreach ($tables as $table => $create) {
                $columns = array_values(array_intersect(self::columns($create), self::existingColumns($db, $table)));
                $select = array_map(static function (string $column) use ($table, $rekeyed, $foreignKeys): string {
                    $target = 'id' === $column && \in_array($table, $rekeyed, true) ? $table : ($foreignKeys[$table.'.'.$column] ?? null);

                    return null === $target
                        ? 'o.'.self::quote($column)
                        : \sprintf("(SELECT m.new FROM _uuid_map m WHERE m.tbl = '%s' AND m.old = o.%s)", $target, self::quote($column));
                }, $columns);
                $db->executeStatement(self::rename($create, $table, '_new_'.$table));
                $db->executeStatement(\sprintf(
                    'INSERT INTO %s (%s) SELECT %s FROM %s o',
                    self::quote('_new_'.$table),
                    implode(', ', array_map(self::quote(...), $columns)),
                    implode(', ', $select),
                    self::quote($table),
                ));
            }
            foreach (array_keys($tables) as $table) {
                $db->executeStatement('DROP TABLE '.self::quote($table));
            }
            foreach (array_keys($tables) as $table) {
                $db->executeStatement(\sprintf('ALTER TABLE %s RENAME TO %s', self::quote('_new_'.$table), self::quote($table)));
            }
            foreach ($indexes as $index) {
                $db->executeStatement($index);
            }
            $db->executeStatement('DROP TABLE _uuid_map');

            if ([] !== $broken = $db->fetchAllAssociative('PRAGMA foreign_key_check')) {
                throw new \RuntimeException('Clés étrangères incohérentes après la migration : '.json_encode($broken));
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $db->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    /** @return list<string> colonnes déclarées par un CREATE TABLE */
    private static function columns(string $create): array
    {
        $body = substr($create, strpos($create, '(') + 1);
        $columns = [];
        foreach (preg_split('/,\s*(?![^(]*\))/', $body) ?: [] as $part) {
            if (preg_match('/^\s*"?([a-z_][a-z0-9_]*)"?\s+[A-Z]/', $part, $m) && !\in_array($m[1], ['PRIMARY', 'FOREIGN', 'UNIQUE', 'CONSTRAINT'], true)) {
                $columns[] = $m[1];
            }
        }

        return $columns;
    }

    /** @return list<string> */
    private static function existingColumns(Connection $db, string $table): array
    {
        /** @var list<array{name: string}> $columns */
        $columns = $db->fetchAllAssociative(\sprintf('PRAGMA table_info(%s)', self::quote($table)));

        return array_column($columns, 'name');
    }

    private static function rename(string $create, string $from, string $to): string
    {
        return (string) preg_replace('/^CREATE TABLE "?'.preg_quote($from, '/').'"? /', 'CREATE TABLE '.self::quote($to).' ', $create, 1);
    }

    private static function quote(string $identifier): string
    {
        return '"'.$identifier.'"';
    }
}
