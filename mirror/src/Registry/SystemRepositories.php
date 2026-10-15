<?php

namespace App\Registry;

use App\Quarantine\Quarantine;
use Doctrine\DBAL\Connection;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Index des dépôts Debian (Packages, Release signé) et RPM (repodata) régénérés à partir des paquets
 * importés, hors quarantaine. Mis en cache tant que le contenu ne change pas (nouvel import, liste noire).
 */
final readonly class SystemRepositories
{
    public function __construct(
        private Connection $db,
        private Quarantine $quarantine,
        private OpenPgp $pgp,
        #[Autowire('%kernel.cache_dir%/system-repositories')]
        private string $cacheDir,
    ) {
    }

    /**
     * Fichiers d'un dépôt apt (dists/<codename>/…), null si la distribution n'a aucun paquet.
     *
     * @return array<string, string>|null chemin relatif à dists/<codename> => contenu
     */
    public function apt(string $distribution): ?array
    {
        $dist = Distributions::find($distribution);
        if (!$dist || Ecosystem::Deb !== $dist['ecosystem']) {
            return null;
        }

        return $this->cached('deb', $distribution, function (array $rows) use ($dist): array {
            // Paquets par section et architecture ; « all » est publié dans chaque architecture
            $stanzas = [];
            $archs = [];
            foreach ($rows as $row) {
                $metadata = json_decode((string) $row['metadata'], true) ?: [];
                if ('all' !== ($metadata['architecture'] ?? 'all')) {
                    $archs[$metadata['architecture']] = true;
                }
            }
            $archs = array_keys($archs) ?: [array_key_first($dist['architectures'])];
            sort($archs);
            foreach ($rows as $row) {
                $metadata = json_decode((string) $row['metadata'], true) ?: [];
                $arch = $metadata['architecture'] ?? 'all';
                $component = $metadata['component'] ?? 'main';
                foreach ('all' === $arch ? $archs : [$arch] as $a) {
                    $stanzas[$component][$a][] = rtrim((string) ($metadata['control'] ?? ''))."\n";
                }
            }
            $components = array_keys($stanzas);
            sort($components);
            $files = [];
            foreach ($components as $component) {
                foreach ($archs as $arch) {
                    $packages = implode("\n", $stanzas[$component][$arch] ?? []);
                    $files["$component/binary-$arch/Packages"] = $packages;
                    $files["$component/binary-$arch/Packages.gz"] = gzencode($packages, 9);
                }
            }
            $release = implode("\n", [
                'Origin: Romulus',
                'Label: Romulus '.$dist['label'],
                'Suite: '.$dist['codename'],
                'Codename: '.$dist['codename'],
                'Version: '.$dist['release'],
                'Date: '.gmdate('D, d M Y H:i:s').' UTC',
                'Architectures: '.implode(' ', $archs),
                'Components: '.implode(' ', $components),
                'Description: Miroir hors ligne Lupa ('.$dist['label'].')',
                'SHA256:',
            ])."\n";
            foreach ($files as $path => $content) {
                $release .= sprintf(" %s %16d %s\n", hash('sha256', $content), \strlen($content), $path);
            }
            $files['Release'] = $release;
            $files['Release.gpg'] = $this->pgp->detached($release);
            $files['InRelease'] = $this->pgp->clearsign($release);

            return $files;
        });
    }

    /**
     * Fichiers repodata d'un dépôt RPM, null s'il n'a aucun paquet.
     *
     * @return array<string, string>|null nom (repomd.xml, <sha>-primary.xml.gz…) => contenu
     */
    public function rpm(string $distribution, string $arch): ?array
    {
        $dist = Distributions::find($distribution);
        if (!$dist || Ecosystem::Rpm !== $dist['ecosystem'] || !isset($dist['architectures'][$arch])) {
            return null;
        }

        return $this->cached('rpm', $distribution.'/'.$arch, function (array $rows): array {
            $primary = $filelists = $other = '';
            foreach ($rows as $row) {
                $metadata = json_decode((string) $row['metadata'], true) ?: [];
                $xml = (string) ($metadata['primary'] ?? '');
                $xml = (string) preg_replace('#<location href="[^"]*"\s*/>#', sprintf('<location href="Packages/%s"/>', htmlspecialchars(basename((string) $row['filename']), \ENT_XML1 | \ENT_QUOTES)), $xml, 1);
                $primary .= $xml."\n";
                if (!preg_match('#<name>([^<]*)</name><arch>([^<]*)</arch><version ([^>]*)/><checksum[^>]*>([^<]+)</checksum>#', $xml, $m)) {
                    continue;
                }
                $head = sprintf('<package pkgid="%s" name="%s" arch="%s"><version %s/>', $m[4], $m[1], $m[2], $m[3]);
                preg_match_all('#<file(?: type="[^"]*")?>[^<]*</file>#', $xml, $files);
                $filelists .= $head.implode('', $files[0])."</package>\n";
                $other .= $head."</package>\n";
            }
            $count = \count($rows);
            $documents = [
                'primary' => sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<metadata xmlns=\"http://linux.duke.edu/metadata/common\" xmlns:rpm=\"http://linux.duke.edu/metadata/rpm\" packages=\"%d\">\n%s</metadata>\n", $count, $primary),
                'filelists' => sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<filelists xmlns=\"http://linux.duke.edu/metadata/filelists\" packages=\"%d\">\n%s</filelists>\n", $count, $filelists),
                'other' => sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<otherdata xmlns=\"http://linux.duke.edu/metadata/other\" packages=\"%d\">\n%s</otherdata>\n", $count, $other),
            ];
            $time = time();
            $files = [];
            $repomd = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<repomd xmlns=\"http://linux.duke.edu/metadata/repo\" xmlns:rpm=\"http://linux.duke.edu/metadata/rpm\">\n  <revision>$time</revision>\n";
            foreach ($documents as $type => $document) {
                $gz = gzencode($document, 9);
                $sha = hash('sha256', $gz);
                $name = $sha.'-'.$type.'.xml.gz';
                $files[$name] = $gz;
                $repomd .= sprintf("  <data type=\"%s\">\n    <checksum type=\"sha256\">%s</checksum>\n    <open-checksum type=\"sha256\">%s</open-checksum>\n    <location href=\"repodata/%s\"/>\n    <timestamp>%d</timestamp>\n    <size>%d</size>\n    <open-size>%d</open-size>\n  </data>\n",
                    $type, $sha, hash('sha256', $document), $name, $time, \strlen($gz), \strlen($document));
            }
            $files['repomd.xml'] = $repomd."</repomd>\n";

            return $files;
        });
    }

    /** Paquet servi par un chemin du dépôt (pool/… ou Packages/<fichier>), hors quarantaine. */
    public function package(Ecosystem $ecosystem, string $filename): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, name, version, blobs FROM package WHERE ecosystem = ? AND filename = ?', [$ecosystem->value, $filename]);
        if (!$row) {
            return null;
        }
        $row['quarantine'] = $this->quarantine->find($ecosystem, (string) $row['name'], (string) $row['version']);

        return $row;
    }

    /**
     * @param callable(list<array<string, mixed>>): array<string, string> $build
     *
     * @return array<string, string>|null
     */
    private function cached(string $ecosystem, string $prefix, callable $build): ?array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT name, version, filename, metadata, updated_at FROM package WHERE ecosystem = ? AND filename LIKE ? ESCAPE '\\' ORDER BY name, version, filename",
            [$ecosystem, addcslashes($prefix, '%_').'/%'],
        );
        $rows = array_values(array_filter($rows, fn (array $r) => null === $this->quarantine->find(Ecosystem::from($ecosystem), (string) $r['name'], (string) $r['version'])));
        // RPM : les paquets d'une architecture (x86_64/…), noarch compris, sont rangés sous <distribution>/<arch>/
        if ([] === $rows) {
            return null;
        }
        $state = hash('sha256', $ecosystem.$prefix.implode("\n", array_map(static fn (array $r) => $r['filename'].'|'.$r['updated_at'], $rows)).$this->pgp->fingerprint());
        $file = $this->cacheDir.'/'.$state.'.ser';
        if (is_file($file) && \is_array($data = @unserialize((string) file_get_contents($file)))) {
            return $data;
        }
        $files = $build($rows);
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
        @file_put_contents($file, serialize($files), \LOCK_EX);

        return $files;
    }
}
