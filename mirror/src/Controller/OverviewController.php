<?php

namespace App\Controller;

use App\Entity\Job;
use App\Entity\TrustedKey;
use App\Quarantine\Quarantine;
use App\Repository\ImportedBundleRepository;
use App\Repository\JobRepository;
use App\Repository\TrustedKeyRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\Signer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api')]
final class OverviewController
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobRepository $jobs,
        private readonly ImportedBundleRepository $bundles,
        private readonly TrustedKeyRepository $keys,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly Quarantine $quarantine,
        private readonly \App\Registry\OpenPgp $pgp,
        private readonly string $storageDir,
        private readonly string $publicUrl,
    ) {
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $ecosystems = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT ecosystem, COUNT(DISTINCT name) AS packages, COUNT(*) AS files, COALESCE(SUM(size), 0) AS size FROM package WHERE substr(name, 1, 6) <> '_flex/' GROUP BY ecosystem"
        ) as $row) {
            $ecosystems[$row['ecosystem']] = ['packages' => (int) $row['packages'], 'files' => (int) $row['files'], 'size' => (int) $row['size']];
        }
        $last = $this->bundles->findOneBy([], ['importedAt' => 'DESC']);

        return new JsonResponse([
            'ecosystems' => (object) $ecosystems,
            'storage' => [
                'size' => $this->storageSize(),
                'free' => is_dir($this->storageDir) ? @disk_free_space($this->storageDir) ?: null : null,
            ],
            'baseUrl' => '' !== $this->publicUrl ? rtrim($this->publicUrl, '/') : $request->getSchemeAndHttpHost(),
            'lastImport' => $last ? Presenter::bundle($last) : null,
            'trustedKeys' => \count($this->keys->findAll()),
            'security' => $this->securityCounts(),
            'quarantine' => ['entries' => \count($this->quarantine->all()), 'present' => \count($this->quarantine->quarantinedPackages())],
            'activeJobs' => array_map(Presenter::job(...), $this->jobs->findBy(['status' => [Job::QUEUED, Job::RUNNING]], ['id' => 'ASC'])),
            'recentJobs' => array_map(Presenter::job(...), $this->jobs->findBy([], ['id' => 'DESC'], 8)),
        ]);
    }

    /** Distributions Debian et RPM servies, avec leurs architectures et sections (page Utilisation). */
    #[Route('/distributions', methods: ['GET'])]
    public function distributions(Request $request): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT ecosystem, substr(filename, 1, instr(filename, '/') - 1) AS distribution, COUNT(*) AS packages,
                    group_concat(DISTINCT json_extract(metadata, '$.repoArch')) AS arches,
                    group_concat(DISTINCT json_extract(metadata, '$.architecture')) AS packageArches,
                    group_concat(DISTINCT json_extract(metadata, '$.component')) AS components
             FROM package WHERE ecosystem IN ('deb', 'rpm') GROUP BY ecosystem, distribution ORDER BY distribution"
        );
        $base = '' !== $this->publicUrl ? rtrim($this->publicUrl, '/') : $request->getSchemeAndHttpHost();
        $items = [];
        foreach ($rows as $row) {
            $dist = \Sas\Bundle\Distributions::find((string) $row['distribution']);
            if (!$dist) {
                continue;
            }
            $deb = 'deb' === $row['ecosystem'];
            $arches = array_values(array_filter(explode(',', (string) ($deb ? $row['packageArches'] : $row['arches'])), static fn ($a) => '' !== $a && 'all' !== $a));
            $items[] = [
                'id' => $row['distribution'],
                'ecosystem' => $row['ecosystem'],
                'label' => $dist['label'],
                'codename' => $dist['codename'],
                'release' => $dist['release'],
                'eol' => $dist['eol'],
                'packages' => (int) $row['packages'],
                'architectures' => $arches,
                'components' => $deb ? array_values(array_filter(explode(',', (string) $row['components']))) : [],
                'repoFile' => $deb ? null : SystemRegistryController::repoFile($base, (string) $row['distribution'], $dist),
            ];
        }

        return new JsonResponse(['items' => $items, 'keyFingerprint' => $this->pgp->fingerprint()]);
    }

    #[Route('/imports', methods: ['GET'])]
    public function imports(): JsonResponse
    {
        return new JsonResponse(array_map(Presenter::bundle(...), $this->bundles->findBy([], ['importedAt' => 'DESC'])));
    }

    #[Route('/keys', methods: ['GET'])]
    public function keys(): JsonResponse
    {
        return new JsonResponse(array_map(Presenter::key(...), $this->keys->findBy([], ['createdAt' => 'ASC'])));
    }

    /** Ajout manuel d'une clé (copiée depuis les réglages du collecteur). */
    #[Route('/keys', methods: ['POST'])]
    public function addKey(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $publicKey = trim((string) ($data['publicKey'] ?? ''));
        $label = trim((string) ($data['label'] ?? '')) ?: 'Collecteur';
        if (!Signer::isPublicKey($publicKey)) {
            throw new BadRequestHttpException('Clé publique invalide : copiez-la depuis Réglages dans le collecteur.');
        }
        if ($existing = $this->keys->findOneBy(['publicKey' => $publicKey])) {
            return new JsonResponse(Presenter::key($existing));
        }
        $key = new TrustedKey($publicKey, mb_substr($label, 0, 120));
        $this->em->persist($key);
        $this->em->flush();

        return new JsonResponse(Presenter::key($key), Response::HTTP_CREATED);
    }

    #[Route('/keys/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteKey(TrustedKey $key): Response
    {
        $this->em->remove($key);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Versions de paquets signalées par Rémus lors de l'export.
     *
     * @return array{critical: int, warning: int, low: int}
     */
    private function securityCounts(): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'low' => 0];
        $rows = $this->db->fetchAllKeyValue(
            "SELECT json_extract(metadata, '$.security.severity') AS s, COUNT(DISTINCT ecosystem || name || version) "
            ."FROM package WHERE json_extract(metadata, '$.security.severity') IS NOT NULL GROUP BY s"
        );
        foreach ($rows as $severity => $n) {
            $level = match ($severity) {
                'critical' => 'critical',
                'low' => 'low',
                default => 'warning',
            };
            $counts[$level] += (int) $n;
        }

        return $counts;
    }

    /** Taille réelle du stockage (fichiers partagés comptés une fois), recalculée au plus toutes les minutes. */
    private function storageSize(): int
    {
        return $this->cache->get('storage_size', function (ItemInterface $item): int {
            $item->expiresAfter(60);

            return $this->diskUsage();
        });
    }

    private function diskUsage(): int
    {
        $total = 0;
        $dir = $this->storageDir.'/sha256';
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $total += $file->getSize();
            }
        }

        return $total;
    }
}
