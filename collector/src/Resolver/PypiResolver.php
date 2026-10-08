<?php

namespace App\Resolver;

use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Python : pip calcule les dépendances pour chaque cible (version de Python × plateforme) sans rien installer
 * (`pip install --dry-run --report`), puis on télécharge les fichiers en vérifiant leur sha256.
 * Les sources (sdist) des versions retenues peuvent être ajoutées.
 */
final readonly class PypiResolver implements Resolver
{
    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        #[Autowire('%env(PYPI_INDEX)%')]
        private string $index,
        #[Autowire('%env(PIP_BIN)%')]
        private string $pip,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Pypi;
    }

    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    /** @return list<array{python: string, platform: string}> */
    public static function targets(PackageList $list): array
    {
        $targets = $list->getOptions()['targets'] ?? [];

        return \is_array($targets) && [] !== $targets ? array_values($targets) : [['python' => '3.12', 'platform' => 'linux-x86_64']];
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach (self::targets($list) as $target) {
            if (!\in_array($target['python'] ?? null, PypiPlatforms::PYTHON_VERSIONS, true)) {
                $errors[] = sprintf('Version de Python non prise en charge : %s.', $target['python'] ?? '?');
            }
            if (!isset(PypiPlatforms::labels()[$target['platform'] ?? ''])) {
                $errors[] = sprintf('Plateforme inconnue : %s.', $target['platform'] ?? '?');
            }
        }
        foreach ($this->requirements($list) as $line) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(\[[^\]]*\])?\s*([<>=!~;@ (]|$)/', $line)) {
                $errors[] = sprintf('« %s » : exigence invalide (attendu : nom, nom==1.2.3, nom>=1,<2…).', $line);
            }
        }
        if ([] === $this->requirements($list)) {
            $errors[] = 'La liste est vide : saisissez des paquets ou importez un requirements.txt.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $requirements = $this->requirements($list);
        $dir = Tool::tempDir('sas-pip');
        $files = [];
        try {
            file_put_contents($dir.'/requirements.txt', implode("\n", $requirements)."\n");
            $targets = self::targets($list);
            foreach ($targets as $i => $target) {
                $label = sprintf('Python %s, %s', $target['python'], PypiPlatforms::labels()[$target['platform']]);
                $context->report('Résolution des dépendances : '.$label, (int) ($i * 30 / \count($targets)));
                $report = $dir.'/report-'.$i.'.json';
                $command = [
                    $this->pip, 'install', '--dry-run', '--ignore-installed', '--quiet', '--no-input',
                    '--report', $report, '--target', $dir.'/target',
                    '--index-url', rtrim($this->index, '/').'/simple',
                    '--only-binary=:all:', '--implementation', 'cp', '--python-version', $target['python'],
                ];
                foreach (PypiPlatforms::tags($target['platform']) as $tag) {
                    array_push($command, '--platform', $tag);
                }
                array_push($command, '-r', $dir.'/requirements.txt');
                try {
                    Tool::run($command, $dir, $context, ['PIP_DISABLE_PIP_VERSION_CHECK' => '1']);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException(sprintf("%s\n\nCible : %s. Si un paquet n'existe qu'en sources (sans wheel), il ne peut pas être résolu pour une autre plateforme ; essayez une autre version ou retirez cette cible.", $e->getMessage(), $label), previous: $e);
                }

                $count = 0;
                foreach (json_decode((string) file_get_contents($report), true)['install'] ?? [] as $item) {
                    $file = self::fileFromReport($item);
                    $files[$file['filename']] ??= $file;
                    ++$count;
                }
                $context->log(sprintf('%s : %d paquets.', $label, $count));
            }
        } finally {
            Tool::removeDir($dir);
        }

        if ($list->getOptions()['sdist'] ?? false) {
            $context->report('Recherche des sources (sdist)', 35);
            foreach ($this->sdists($files, $context) as $file) {
                $files[$file['filename']] ??= $file;
            }
        }

        ksort($files);
        $artifacts = [];
        $done = 0;
        foreach ($files as $file) {
            ++$done;
            $artifact = $this->catalog->complete(Ecosystem::Pypi, $file['name'], $file['version'], $file['filename']);
            if (!$artifact) {
                $context->report('Téléchargement '.$file['filename'], 40 + (int) ($done * 60 / \count($files)));
                $blob = $this->downloader->fetch($file['url'], sha256: $file['sha256']);
                $artifact = $this->catalog->record(Ecosystem::Pypi, $file['name'], $file['version'], $file['filename'], [$blob], [
                    'displayName' => $file['displayName'],
                    'requiresPython' => $file['requiresPython'],
                ]);
            }
            $artifacts[] = $artifact;
            if (0 === $done % 50) {
                $this->catalog->flush();
            }
        }
        $this->catalog->flush();
        $context->log(sprintf('%d fichiers.', \count($artifacts)));

        return $artifacts;
    }

    /** @return list<string> */
    private function requirements(PackageList $list): array
    {
        $lines = $list->getSpecLines();
        foreach (preg_split('/\R/', (string) $list->getLockfile()) ?: [] as $line) {
            // Lignes de continuation, options (-r, -e, --index-url…) et empreintes : on ne garde que les exigences
            $line = trim((string) preg_replace(['/\s+#.*$/', '/\s*--hash[= ]\S+/', '/\s*\\\\$/'], '', $line));
            if ('' !== $line && !str_starts_with($line, '#') && !str_starts_with($line, '-')) {
                $lines[] = $line;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param array<string, mixed> $item élément « install » du rapport de pip
     *
     * @return array{name: string, displayName: string, version: string, filename: string, url: string, sha256: string, requiresPython: string|null}
     */
    private static function fileFromReport(array $item): array
    {
        $url = (string) ($item['download_info']['url'] ?? '');
        $archive = $item['download_info']['archive_info'] ?? [];
        $sha256 = $archive['hashes']['sha256'] ?? (str_starts_with($archive['hash'] ?? '', 'sha256=') ? substr($archive['hash'], 7) : null);
        if ('' === $url || !\is_string($sha256)) {
            throw new \RuntimeException(sprintf('Rapport pip incomplet pour %s (dépendance hors index, VCS ou fichier local ?).', $item['metadata']['name'] ?? '?'));
        }

        return [
            'name' => self::normalize((string) $item['metadata']['name']),
            'displayName' => (string) $item['metadata']['name'],
            'version' => (string) $item['metadata']['version'],
            'filename' => rawurldecode(basename((string) parse_url($url, \PHP_URL_PATH))),
            'url' => $url,
            'sha256' => strtolower($sha256),
            'requiresPython' => $item['metadata']['requires_python'] ?? null,
        ];
    }

    /**
     * @param array<string, array{name: string, displayName: string, version: string}> $files
     *
     * @return list<array{name: string, displayName: string, version: string, filename: string, url: string, sha256: string, requiresPython: string|null}>
     */
    private function sdists(array $files, JobContext $context): array
    {
        $versions = [];
        foreach ($files as $file) {
            $versions[$file['name'].'=='.$file['version']] = $file;
        }
        $sdists = [];
        foreach ($versions as $file) {
            $data = $this->downloader->json(sprintf('%s/pypi/%s/%s/json', rtrim($this->index, '/'), $file['name'], $file['version']));
            $found = false;
            foreach ($data['urls'] ?? [] as $url) {
                if ('sdist' === ($url['packagetype'] ?? null) && !($url['yanked'] ?? false)) {
                    $sdists[] = [
                        'name' => $file['name'],
                        'displayName' => $file['displayName'],
                        'version' => $file['version'],
                        'filename' => (string) $url['filename'],
                        'url' => (string) $url['url'],
                        'sha256' => (string) $url['digests']['sha256'],
                        'requiresPython' => $url['requires_python'] ?? null,
                    ];
                    $found = true;
                }
            }
            if (!$found) {
                $context->log(sprintf('Pas de sources publiées pour %s %s.', $file['displayName'], $file['version']));
            }
        }

        return $sdists;
    }
}
