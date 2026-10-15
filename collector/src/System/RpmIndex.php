<?php

namespace App\System;

use App\Job\JobContext;
use Sas\Bundle\Distributions;

/**
 * Télécharge, vérifie (repomd.xml signé) et analyse les métadonnées primary.xml des dépôts
 * BaseOS/AppStream… d'une distribution RPM. EL8 : lit aussi modules.yaml (streams par défaut).
 */
final readonly class RpmIndex
{

    public function __construct(
        private IndexFiles $files,
        private Signatures $signatures,
    ) {
    }

    /** Architecture des paquets : x86_64_v2 (dépôt AlmaLinux 10) publie des paquets « x86_64 ». */
    public static function packageArch(string $arch): string
    {
        return 'x86_64_v2' === $arch ? 'x86_64' : $arch;
    }

    /** @param list<string> $sections BaseOS, AppStream… */
    public function load(string $distribution, string $arch, array $sections, JobContext $context): RpmRepository
    {
        $dist = Distributions::find($distribution) ?? throw new \InvalidArgumentException(sprintf('Distribution inconnue : %s.', $distribution));
        $sources = [];
        $modules = [];
        foreach ($sections as $section) {
            $repo = $dist['repos'][$section] ?? throw new \InvalidArgumentException(sprintf('Dépôt inconnu pour %s : %s.', $dist['label'], $section));
            $base = str_replace('$arch', $arch, $repo['url']);
            $repomd = (string) $this->files->text($base.'/repodata/repomd.xml');
            $this->signatures->detached($repomd, (string) $this->files->text($base.'/repodata/repomd.xml.asc'), $repo['keys'], $dist['label'].' '.$section);
            $data = self::repomd($repomd);
            if (!isset($data['primary_db'])) {
                throw new \RuntimeException(sprintf('Pas de base primary_db dans %s.', $base));
            }
            $sqlite = $this->files->plain($base.'/'.$data['primary_db']['href'], $data['primary_db']['algo'], $data['primary_db']['digest'], $context, $dist['label'].' '.$section.' '.$arch);
            $sources[] = ['name' => $section, 'base' => $base, 'sqlite' => $sqlite];
            if (($dist['modules'] ?? false) && isset($data['modules'])) {
                $modules[] = $this->files->plain($base.'/'.$data['modules']['href'], $data['modules']['algo'], $data['modules']['digest'], $context, $section.' modules');
            }
        }
        $repository = new RpmRepository($sources, [self::packageArch($arch), 'noarch']);
        foreach ($modules as $file) {
            self::modules((string) file_get_contents($file), $repository);
        }

        return $repository;
    }

    /** @return array<string, array{href: string, algo: string, digest: string}> */
    public static function repomd(string $xml): array
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new \RuntimeException('repomd.xml illisible.');
        }
        $data = [];
        foreach ($doc->getElementsByTagName('data') as $node) {
            $checksum = $node->getElementsByTagName('checksum')->item(0);
            $location = $node->getElementsByTagName('location')->item(0);
            if (!$checksum || !$location) {
                continue;
            }
            $algo = strtolower($checksum->getAttribute('type'));
            $data[$node->getAttribute('type')] = [
                'href' => $location->getAttribute('href'),
                'algo' => 'sha' === $algo ? 'sha1' : $algo,
                'digest' => trim($checksum->textContent),
            ];
        }

        return $data;
    }

    /**
     * Élément <package> de primary.xml d'un paquet, régénéré depuis la base du dépôt (Romulus en
     * reconstitue son propre primary.xml).
     */
    public static function xml(\PDO $pdo, int $key): string
    {
        $statement = $pdo->prepare('SELECT * FROM packages WHERE pkgKey = ?');
        $statement->execute([$key]);
        $p = $statement->fetch(\PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('Paquet RPM inconnu.');
        $e = static fn (mixed $v) => htmlspecialchars((string) $v, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $xml = '<package type="rpm">'
            .'<name>'.$e($p['name']).'</name><arch>'.$e($p['arch']).'</arch>'
            .sprintf('<version epoch="%s" ver="%s" rel="%s"/>', $e($p['epoch']), $e($p['version']), $e($p['release']))
            .sprintf('<checksum type="%s" pkgid="YES">%s</checksum>', $e($p['checksum_type']), $e($p['pkgId']))
            .'<summary>'.$e($p['summary']).'</summary><description>'.$e($p['description']).'</description>'
            .'<packager>'.$e($p['rpm_packager']).'</packager><url>'.$e($p['url']).'</url>'
            .sprintf('<time file="%d" build="%d"/>', $p['time_file'], $p['time_build'])
            .sprintf('<size package="%d" installed="%d" archive="%d"/>', $p['size_package'], $p['size_installed'], $p['size_archive'])
            .sprintf('<location href="%s"/>', $e($p['location_href']))
            .'<format>'
            .'<rpm:license>'.$e($p['rpm_license']).'</rpm:license><rpm:vendor>'.$e($p['rpm_vendor']).'</rpm:vendor>'
            .'<rpm:group>'.$e($p['rpm_group']).'</rpm:group><rpm:buildhost>'.$e($p['rpm_buildhost']).'</rpm:buildhost>'
            .'<rpm:sourcerpm>'.$e($p['rpm_sourcerpm']).'</rpm:sourcerpm>'
            .sprintf('<rpm:header-range start="%d" end="%d"/>', $p['rpm_header_start'], $p['rpm_header_end']);
        foreach (['provides', 'requires', 'conflicts', 'obsoletes', 'suggests', 'enhances', 'recommends', 'supplements'] as $table) {
            try {
                $deps = $pdo->prepare(sprintf('SELECT * FROM %s WHERE pkgKey = ?', $table));
                $deps->execute([$key]);
                $rows = $deps->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException) {
                continue; // table absente (EL7 : pas de dépendances faibles)
            }
            if ([] === $rows) {
                continue;
            }
            $xml .= '<rpm:'.$table.'>';
            foreach ($rows as $d) {
                $xml .= '<rpm:entry name="'.$e($d['name']).'"';
                if (null !== $d['flags']) {
                    $xml .= sprintf(' flags="%s" epoch="%s" ver="%s"', $e($d['flags']), $e($d['epoch'] ?? '0'), $e($d['version']));
                    if (null !== $d['release']) {
                        $xml .= ' rel="'.$e($d['release']).'"';
                    }
                }
                if (!empty($d['pre']) && 'FALSE' !== strtoupper((string) $d['pre'])) {
                    $xml .= ' pre="1"';
                }
                $xml .= '/>';
            }
            $xml .= '</rpm:'.$table.'>';
        }
        $files = $pdo->prepare('SELECT name, type FROM files WHERE pkgKey = ?');
        $files->execute([$key]);
        foreach ($files->fetchAll(\PDO::FETCH_NUM) as [$name, $type]) {
            $xml .= '<file'.('file' !== $type && '' !== (string) $type ? ' type="'.$e($type).'"' : '').'>'.$e($name).'</file>';
        }

        return $xml.'</format></package>';
    }

    /**
     * EL8 : streams par défaut et paquets appartenant à un module. Le format modulemd est fixe ;
     * lu sans analyseur YAML (les clés numériques non quotées, « 1.10: [common] », y sont courantes).
     */
    public static function modules(string $yaml, RpmRepository $repository): void
    {
        $value = static fn (string $doc, string $key) => preg_match('/^  '.$key.':\s*["\']?([^"\'\n]+?)["\']?\s*$/m', $doc, $m) ? $m[1] : null;
        foreach (preg_split('/^---\s*$/m', $yaml) ?: [] as $document) {
            if (preg_match('/^document:\s*modulemd-defaults\s*$/m', $document)) {
                $module = $value($document, 'module');
                $stream = $value($document, 'stream');
                if (null !== $module && null !== $stream) {
                    $repository->defaultStreams[$module] = $stream;
                }
            } elseif (preg_match('/^document:\s*modulemd\s*$/m', $document)) {
                $name = $value($document, 'name');
                $stream = $value($document, 'stream');
                if (null === $name || null === $stream || !preg_match('/^  artifacts:\s*\n    rpms:\s*\n((?:    - .+\n?)+)/m', $document, $m)) {
                    continue;
                }
                $requires = self::moduleRequires($document);
                foreach (explode("\n", trim($m[1])) as $line) {
                    $nevra = trim(substr(trim($line), 2), " \"'");
                    $repository->modular[$nevra] = $name.':'.$stream;
                    if ([] !== $requires) {
                        $repository->moduleRequires[$nevra] = $requires;
                    }
                }
            }
        }
    }

    /**
     * Modules requis à l'exécution par un contexte de module (« perl: [5.32] ») ; plusieurs entrées :
     * une seule doit être satisfaite.
     *
     * @return list<array<string, list<string>>>
     */
    private static function moduleRequires(string $document): array
    {
        if (!preg_match('/^  dependencies:\s*\n((?:    .*\n|  - .*\n)+)/m', $document."\n", $m)) {
            return [];
        }
        $entries = [];
        $current = null;
        $inRequires = false;
        foreach (explode("\n", $m[1]) as $line) {
            if (str_starts_with($line, '  - ')) {
                null === $current || $entries[] = $current;
                $current = [];
                $line = '    '.substr($line, 4);
            }
            if (preg_match('/^    (\w+):\s*$/', $line, $k)) {
                $inRequires = 'requires' === $k[1];
                continue;
            }
            if ($inRequires && preg_match('/^      ([\w.+-]+):\s*\[(.*)\]\s*$/', $line, $r) && 'platform' !== $r[1]) {
                $current[$r[1]] = array_values(array_filter(array_map(static fn ($v) => trim($v, " \"'"), explode(',', $r[2])), 'strlen'));
            }
        }
        null === $current || $entries[] = $current;

        return array_values(array_filter($entries));
    }
}
