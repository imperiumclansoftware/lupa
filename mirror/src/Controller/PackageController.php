<?php

namespace App\Controller;

use App\Entity\Package;
use App\Quarantine\Quarantine;
use App\Repository\PackageRepository;
use Doctrine\DBAL\Connection;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/packages')]
final class PackageController
{
    private const int PAGE_SIZE = 50;

    private const string SEVERITY_SQL = "MAX(CASE json_extract(metadata, '$.security.severity') WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'moderate' THEN 3 WHEN 'unknown' THEN 2 WHEN 'low' THEN 1 ELSE 0 END)";

    private const array SEVERITIES = [5 => 'critical', 4 => 'high', 3 => 'moderate', 2 => 'unknown', 1 => 'low'];

    public function __construct(
        private readonly Connection $db,
        private readonly PackageRepository $packages,
        private readonly Quarantine $quarantine,
    ) {
    }

    /** Paquets regroupés par nom : ?ecosystem=npm&q=lod&page=1 */
    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        // Recettes Symfony Flex (_flex/…) : servies sous /composer/flex, pas des paquets
        $where = ["name NOT LIKE '\\_flex/%' ESCAPE '\\'"];
        $params = [];
        if ($ecosystem = Ecosystem::tryFrom($request->query->getString('ecosystem'))) {
            $where[] = 'ecosystem = ?';
            $params[] = $ecosystem->value;
        }
        if ('' !== $q = trim($request->query->getString('q'))) {
            $where[] = "LOWER(name) LIKE ? ESCAPE '\\'";
            $params[] = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        }
        $sql = 'FROM package WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM (SELECT 1 '.$sql.' GROUP BY ecosystem, name)', $params);
        $page = max(1, $request->query->getInt('page', 1));
        $rows = $this->db->fetchAllAssociative(
            'SELECT ecosystem, name, COUNT(DISTINCT version) AS versions, COUNT(*) AS files, SUM(size) AS size, MAX(updated_at) AS updated_at, '
            // Gravité la plus élevée parmi les versions (alertes transportées par les bundles)
            .self::SEVERITY_SQL.' AS severity '
            .$sql.' GROUP BY ecosystem, name ORDER BY name LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page - 1) * self::PAGE_SIZE),
            $params,
        );

        $quarantined = [];
        foreach ($this->quarantine->quarantinedPackages() as $q) {
            $quarantined[$q['ecosystem'].'|'.$q['name']] = ($quarantined[$q['ecosystem'].'|'.$q['name']] ?? 0) + 1;
        }

        return new JsonResponse([
            'items' => array_map(static fn (array $r) => [
                'ecosystem' => $r['ecosystem'],
                'name' => $r['name'],
                'versions' => (int) $r['versions'],
                'files' => (int) $r['files'],
                'size' => (int) $r['size'],
                'updatedAt' => (new \DateTimeImmutable($r['updated_at']))->format(\DATE_ATOM),
                'severity' => self::SEVERITIES[(int) $r['severity']] ?? null,
                'quarantined' => $quarantined[$r['ecosystem'].'|'.$r['name']] ?? 0,
            ], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ]);
    }

    #[Route('/{ecosystem}/{name}', methods: ['GET'], requirements: ['name' => '.+'])]
    public function show(string $ecosystem, string $name): JsonResponse
    {
        $eco = Ecosystem::tryFrom($ecosystem) ?? throw new NotFoundHttpException();
        $items = $this->packages->findBy(['ecosystem' => $eco, 'name' => $name]);
        if ([] === $items) {
            throw new NotFoundHttpException('Paquet absent du miroir.');
        }
        usort($items, static fn (Package $a, Package $b) => version_compare($b->getVersion(), $a->getVersion()) ?: strcmp((string) $a->getFilename(), (string) $b->getFilename()));
        $first = $items[0]->getMetadata();

        return new JsonResponse([
            'ecosystem' => $eco->value,
            'name' => $name,
            'description' => \is_string($first['description'] ?? null) ? $first['description'] : null,
            'items' => array_map(fn (Package $p) => Presenter::package($p, $this->quarantine->forPackage($p)), $items),
        ]);
    }

    /** Liste noire reçue des collecteurs (lecture seule : elle se règle dans Rémus). */
    #[Route('/-/quarantine', methods: ['GET'], priority: 10)]
    public function quarantine(): JsonResponse
    {
        $present = [];
        foreach ($this->quarantine->quarantinedPackages() as $q) {
            $present[$q['entry']->getId()][] = ['version' => $q['version'], 'files' => $q['files']];
        }
        $collectors = [];
        foreach ($this->db->fetchAllAssociative("SELECT name, value FROM setting WHERE name LIKE 'blacklist.%'") as $row) {
            $collectors[] = ['id' => substr((string) $row['name'], 10)] + (json_decode((string) $row['value'], true) ?: []);
        }

        return new JsonResponse([
            'collectors' => $collectors,
            'items' => array_map(static fn ($e) => Presenter::quarantine($e) + ['present' => $present[$e->getId()] ?? []], $this->quarantine->all()),
        ]);
    }
}
