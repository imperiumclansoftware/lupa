<?php

namespace App\Controller;

use App\Entity\Artifact;
use App\Entity\Job;
use App\Job\JobLauncher;
use App\Repository\ArtifactRepository;
use App\Repository\JobRepository;
use App\Repository\PackageListRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Alertes de sécurité : paquets vulnérables et nouvelle analyse. */
#[Route('/api/security')]
final class SecurityController
{
    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly PackageListRepository $lists,
        private readonly JobRepository $jobs,
        private readonly JobLauncher $launcher,
        private readonly Connection $db,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var list<Artifact> $vulnerable */
        $vulnerable = $this->artifacts->createQueryBuilder('a')
            ->where('a.severity IS NOT NULL')
            ->getQuery()->getResult();
        usort($vulnerable, static fn (Artifact $a, Artifact $b) => \App\Audit\Severity::ORDER[$b->getSeverity()] <=> \App\Audit\Severity::ORDER[$a->getSeverity()]
            ?: [$a->getName(), $a->getVersion()] <=> [$b->getName(), $b->getVersion()]);

        // Une ligne par version (les fichiers Python d'une même version partagent leurs failles)
        $items = [];
        foreach ($vulnerable as $artifact) {
            $key = $artifact->getEcosystem()->value.'|'.$artifact->getName().'|'.$artifact->getVersion();
            $items[$key] ??= Presenter::artifact($artifact, true) + [
                'lists' => array_values(array_unique($artifact->getLists()->map(static fn ($l) => ['id' => $l->getId(), 'name' => $l->getName()])->toArray(), \SORT_REGULAR)),
            ];
        }

        $stats = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN audited_at IS NOT NULL THEN 1 ELSE 0 END) AS audited, MIN(audited_at) AS oldest
             FROM artifact WHERE ecosystem IN ('npm', 'pypi', 'composer') AND name NOT LIKE '\\_flex/%' ESCAPE '\\'"
        ) ?: [];
        $active = $this->jobs->findOneBy(['type' => 'audit', 'status' => [Job::QUEUED, Job::RUNNING]]);

        return new JsonResponse([
            'counts' => self::counts(array_values($items)),
            'auditable' => (int) ($stats['total'] ?? 0),
            'audited' => (int) ($stats['audited'] ?? 0),
            'oldestAudit' => isset($stats['oldest']) ? (new \DateTimeImmutable($stats['oldest']))->format(\DATE_ATOM) : null,
            'job' => $active ? Presenter::job($active) : null,
            'items' => array_values($items),
        ]);
    }

    /** Nouvelle analyse : {listId} pour une liste, sinon tout le collecteur. */
    #[Route('/audit', methods: ['POST'])]
    public function audit(Request $request): JsonResponse
    {
        $listId = $request->toArray()['listId'] ?? null;
        $list = null !== $listId ? $this->lists->find((int) $listId) : null;
        $job = $this->launcher->launch('audit', $list ? sprintf('Analyse de sécurité de « %s »', $list->getName()) : 'Analyse de sécurité de tous les paquets', $list);

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    /**
     * Nombre de paquets par niveau d'alerte.
     *
     * @param list<array{severity: string|null}> $items
     *
     * @return array{critical: int, warning: int, low: int}
     */
    public static function counts(array $items): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'low' => 0];
        foreach ($items as $item) {
            match ($item['severity']) {
                'critical' => ++$counts['critical'],
                'high', 'moderate', 'unknown' => ++$counts['warning'],
                'low' => ++$counts['low'],
                default => null,
            };
        }

        return $counts;
    }
}
