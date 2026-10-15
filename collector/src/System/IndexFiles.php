<?php

namespace App\System;

use App\Http\DownloadException;
use App\Job\JobContext;
use Sas\Bundle\IntegrityException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fichiers d'index des dépôts système (Packages.xz, primary.xml.gz…) : téléchargés une fois, vérifiés
 * contre l'empreinte annoncée par l'index signé, décompressés et rangés en cache par empreinte.
 */
final readonly class IndexFiles
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(SYSTEM_CACHE_DIR)%')]
        private string $cacheDir,
    ) {
    }

    public function dir(string $sub): string
    {
        $dir = $this->cacheDir.'/'.$sub;
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le cache des index %s.', $dir));
        }

        return $dir;
    }

    /** Petit fichier (InRelease, repomd.xml) toujours relu : il dit ce qui a changé. */
    public function text(string $url, bool $optional = false): ?string
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 60]);
            $status = $response->getStatusCode();
            if (404 === $status && $optional) {
                return null;
            }
            if (200 !== $status) {
                throw new DownloadException(sprintf('HTTP %d pour %s', $status, $url), $status);
            }

            return $response->getContent();
        } catch (DownloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DownloadException(sprintf('Dépôt injoignable (%s) : %s', $url, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Fichier d'index décompressé, téléchargé si absent du cache.
     *
     * @param string $algo algorithme de l'empreinte annoncée (sha256, sha512, sha1)
     *
     * @return string chemin du fichier décompressé
     */
    public function plain(string $url, string $algo, string $digest, JobContext $context, string $label): string
    {
        $dir = $this->dir('files');
        $target = $dir.'/'.$algo.'-'.$digest;
        if (is_file($target)) {
            touch($target);

            return $target;
        }

        $context->report('Téléchargement de l\'index '.$label);
        $tmp = $target.'.part-'.bin2hex(random_bytes(4));
        $out = fopen($tmp, 'w');
        $hash = hash_init($algo);
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 120, 'max_duration' => 0]);
            if (200 !== $status = $response->getStatusCode()) {
                throw new DownloadException(sprintf('HTTP %d pour %s', $status, $url), $status);
            }
            foreach ($this->http->stream($response) as $chunk) {
                $data = $chunk->getContent();
                hash_update($hash, $data);
                fwrite($out, $data);
            }
        } catch (DownloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DownloadException(sprintf('Téléchargement impossible de %s : %s', $url, $e->getMessage()), previous: $e);
        } finally {
            fclose($out);
        }
        if (!hash_equals(strtolower($digest), hash_final($hash))) {
            @unlink($tmp);
            throw new IntegrityException(sprintf('Index %s altéré : son empreinte ne correspond pas à l\'index signé.', $url));
        }

        $this->decompress($tmp, $target.'.tmp', $url);
        @unlink($tmp);
        rename($target.'.tmp', $target);
        $this->prune($dir);

        return $target;
    }

    private function decompress(string $from, string $to, string $url): void
    {
        $command = match (true) {
            str_ends_with($url, '.xz') => ['xz', '-dc', $from],
            str_ends_with($url, '.zst') => ['zstd', '-dcq', $from],
            str_ends_with($url, '.bz2') => ['bzip2', '-dc', $from],
            str_ends_with($url, '.gz') => ['gzip', '-dc', $from],
            default => null,
        };
        if (null === $command) {
            copy($from, $to);

            return;
        }
        $process = Process::fromShellCommandline('"${:BIN}" "${:FLAGS}" "${:IN}" > "${:OUT}"', null, null, null, 600);
        $process->run(null, ['BIN' => $command[0], 'FLAGS' => $command[1], 'IN' => $command[2], 'OUT' => $to]);
        if (!$process->isSuccessful()) {
            @unlink($to);
            throw new \RuntimeException(sprintf('Décompression impossible de %s : %s', basename($url), trim($process->getErrorOutput())));
        }
    }

    /** Ne garde que les index utilisés ces 14 derniers jours. */
    private function prune(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (filemtime($file) < time() - 14 * 86400) {
                @unlink($file);
            }
        }
    }
}
