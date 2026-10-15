<?php

namespace App\System;

use Sas\Bundle\RpmVersion;

/**
 * Index d'une distribution RPM pour une architecture (BaseOS, AppStream… confondus), interrogé à la
 * demande dans les bases primary.sqlite des dépôts (index sur les noms, capacités et fichiers).
 * Identifiant d'un paquet : numéro du dépôt × 2^32 + pkgKey.
 */
final class RpmRepository
{
    /** @var array<string, string> NEVRA modulaire (nom-époque:version-release.arch) => « module:stream » (EL8) */
    public array $modular = [];

    /** @var array<string, string> module => stream par défaut */
    public array $defaultStreams = [];

    /** @var array<string, list<array<string, list<string>>>> NEVRA modulaire => modules requis par son contexte */
    public array $moduleRequires = [];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    /** @var list<\PDO> */
    private array $db = [];

    /**
     * @param list<array{name: string, base: string, sqlite: string}> $sources
     * @param list<string>                                             $arches  architectures retenues (x86_64, noarch)
     */
    public function __construct(public array $sources, private readonly array $arches)
    {
        foreach ($sources as $source) {
            $pdo = new \PDO('sqlite:'.$source['sqlite'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY]);
            $this->db[] = $pdo;
        }
    }

    public function count(): int
    {
        $n = 0;
        foreach ($this->db as $pdo) {
            $n += (int) $this->query($pdo, 'SELECT COUNT(*) FROM packages WHERE arch IN (%s)')->fetchColumn();
        }

        return $n;
    }

    /** @return array{n: string, e: int, v: string, r: string, a: string, h: string, ht: string, loc: string, s: int, src: int, key: int, d: string} */
    public function package(int $id): array
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        [$source, $key] = [$id >> 32, $id & 0xFFFFFFFF];
        $pdo = $this->db[$source];
        $statement = $this->statement($pdo, 'SELECT name, epoch, version, release, arch, pkgId, checksum_type, location_href, size_package, summary FROM packages WHERE pkgKey = ?');
        $statement->execute([$key]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        if (false === $row) {
            throw new \OutOfBoundsException('Paquet RPM inconnu.');
        }
        $algo = strtolower((string) $row[6]);

        return $this->cache[$id] = [
            'n' => $row[0], 'e' => (int) $row[1], 'v' => $row[2], 'r' => $row[3], 'a' => $row[4],
            'h' => $row[5], 'ht' => 'sha' === $algo ? 'sha1' : $algo, 'loc' => $row[7], 's' => (int) $row[8],
            'src' => $source, 'key' => $key, 'd' => mb_substr((string) $row[9], 0, 160),
        ];
    }

    /** Dépendances d'un paquet (requires, recommends, provides), en texte compact. */
    public function deps(int $id, string $table): string
    {
        return $this->depCache[$table][$id] ??= $this->readDeps($this->db[$id >> 32], $table, $id & 0xFFFFFFFF);
    }

    public static function evr(array $p): string
    {
        return RpmVersion::format($p['e'], $p['v'], $p['r']);
    }

    /** @return list<int> la plus récente d'abord */
    public function versions(string $name): array
    {
        $ids = [];
        foreach ($this->db as $source => $pdo) {
            foreach ($this->query($pdo, 'SELECT pkgKey FROM packages WHERE name = ? AND arch IN (%s)', [$name])->fetchAll(\PDO::FETCH_COLUMN) as $key) {
                $ids[] = ($source << 32) | (int) $key;
            }
        }

        return $this->sorted($ids);
    }

    /** @return list<int> paquets fournissant une capacité ou un fichier, du plus récent au plus ancien */
    public function providers(string $capability): array
    {
        $ids = [];
        foreach ($this->db as $source => $pdo) {
            $sql = 'SELECT p.pkgKey FROM provides v JOIN packages p ON p.pkgKey = v.pkgKey WHERE v.name = ? AND p.arch IN (%s)';
            if (str_starts_with($capability, '/')) {
                $sql .= ' UNION SELECT p.pkgKey FROM files f JOIN packages p ON p.pkgKey = f.pkgKey WHERE f.name = ? AND p.arch IN (%2$s)';
            }
            $params = str_starts_with($capability, '/') ? [$capability, $capability] : [$capability];
            foreach ($this->query($pdo, $sql, $params, str_starts_with($capability, '/') ? 2 : 1)->fetchAll(\PDO::FETCH_COLUMN) as $key) {
                $ids[] = ($source << 32) | (int) $key;
            }
        }

        return $this->sorted(array_values(array_unique($ids)));
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->db as $pdo) {
            foreach ($this->query($pdo, 'SELECT DISTINCT name FROM packages WHERE arch IN (%s)')->fetchAll(\PDO::FETCH_COLUMN) as $name) {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }

    /** Le paquet satisfait-il « capacité FLAGS evr » ? */
    public function satisfies(int $id, string $name, ?string $flags, ?string $evr): bool
    {
        $p = $this->package($id);
        if (null === $flags || null === $evr) {
            return true;
        }
        if ($p['n'] === $name && RpmVersion::satisfies(self::evr($p), $flags, $evr)) {
            return true;
        }
        foreach (self::parseDeps($this->deps($id, 'provides')) as [$pname, , $pevr]) {
            // Capacité fournie sans version : satisfait toute contrainte (comme rpm)
            if ($pname === $name && (null === $pevr || RpmVersion::satisfies($pevr, $flags, $evr))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{string, string|null, string|null}> */
    public static function parseDeps(string $packed): array
    {
        $deps = [];
        foreach ('' === $packed ? [] : explode("\n", $packed) as $line) {
            $parts = explode("\t", $line);
            $deps[] = [$parts[0], ($parts[1] ?? '') ?: null, ($parts[2] ?? '') ?: null];
        }

        return $deps;
    }

    /** Base du dépôt d'un paquet (pour générer son élément <package>). */
    public function pdo(int $source): \PDO
    {
        return $this->db[$source];
    }

    /** @var array<string, \PDOStatement> */
    private array $statements = [];

    /** @var array<string, array<int, string>> */
    private array $depCache = [];

    private function statement(\PDO $pdo, string $sql): \PDOStatement
    {
        $key = spl_object_id($pdo).$sql;

        return $this->statements[$key] ??= $pdo->prepare($sql);
    }

    /** @param list<string> $params */
    private function query(\PDO $pdo, string $sql, array $params = [], int $archLists = 1): \PDOStatement
    {
        $placeholders = implode(',', array_fill(0, \count($this->arches), '?'));
        $sql = 2 === $archLists ? sprintf($sql, $placeholders, $placeholders) : sprintf($sql, $placeholders);
        $values = [];
        if (2 === $archLists) {
            $values = [$params[0], ...$this->arches, ...\array_slice($params, 1), ...$this->arches];
        } else {
            $values = [...$params, ...$this->arches];
        }
        $statement = $this->statement($pdo, $sql);
        $statement->execute($values);

        return $statement;
    }

    private function readDeps(\PDO $pdo, string $table, int $key): string
    {
        $exists = $this->statement($pdo, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $exists->execute([$table]);
        if (false === $exists->fetchColumn()) {
            return '';
        }
        $statement = $this->statement($pdo, sprintf('SELECT name, flags, epoch, version, release FROM %s WHERE pkgKey = ?', $table));
        $statement->execute([$key]);
        $lines = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$name, $flags, $epoch, $version, $release]) {
            if (str_starts_with((string) $name, 'rpmlib(')) {
                continue;
            }
            $evr = null !== $flags && null !== $version ? RpmVersion::format((int) $epoch, (string) $version, null !== $release ? (string) $release : null) : '';
            $lines[] = $name.(null !== $flags ? "\t".$flags."\t".$evr : '');
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        usort($ids, fn (int $a, int $b) => RpmVersion::compare(self::evr($this->package($b)), self::evr($this->package($a))));

        return $ids;
    }
}
