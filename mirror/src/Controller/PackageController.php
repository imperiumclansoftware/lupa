<?php

namespace App\Controller;

use App\Entity\Package;
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

    public function __construct(
        private readonly Connection $db,
        private readonly PackageRepository $packages,
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
            'SELECT ecosystem, name, COUNT(DISTINCT version) AS versions, COUNT(*) AS files, SUM(size) AS size, MAX(updated_at) AS updated_at '
            .$sql.' GROUP BY ecosystem, name ORDER BY name LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page - 1) * self::PAGE_SIZE),
            $params,
        );

        return new JsonResponse([
            'items' => array_map(static fn (array $r) => [
                'ecosystem' => $r['ecosystem'],
                'name' => $r['name'],
                'versions' => (int) $r['versions'],
                'files' => (int) $r['files'],
                'size' => (int) $r['size'],
                'updatedAt' => (new \DateTimeImmutable($r['updated_at']))->format(\DATE_ATOM),
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
            'items' => array_map(Presenter::package(...), $items),
        ]);
    }
}
