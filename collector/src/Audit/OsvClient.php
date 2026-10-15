<?php

namespace App\Audit;

use App\Entity\Advisory;
use App\Http\DownloadException;
use App\Repository\AdvisoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Base de vulnérabilités OSV.dev (avis GitHub, PyPA, npm, Packagist…), fiches mises en cache. */
final readonly class OsvClient
{
    public const array ECOSYSTEMS = ['npm' => 'npm', 'pypi' => 'PyPI', 'composer' => 'Packagist'];

    public function __construct(
        private HttpClientInterface $http,
        private AdvisoryRepository $advisories,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Vulnérabilités de chaque (écosystème OSV, paquet, version), par lots de 1000.
     *
     * @param list<array{ecosystem: string, name: string, version: string}> $queries
     *
     * @return list<list<array{id: string, modified: string}>> dans l'ordre des requêtes
     */
    public function query(array $queries): array
    {
        $results = [];
        foreach (array_chunk($queries, 1000) as $chunk) {
            $body = ['queries' => array_map(static fn (array $q) => [
                'package' => ['name' => $q['name'], 'ecosystem' => $q['ecosystem']],
                'version' => $q['version'],
            ], $chunk)];
            $response = $this->http->request('POST', 'https://api.osv.dev/v1/querybatch', ['json' => $body, 'timeout' => 120]);
            if (200 !== $response->getStatusCode()) {
                throw new DownloadException(sprintf('OSV.dev indisponible (HTTP %d).', $response->getStatusCode()));
            }
            foreach ($response->toArray()['results'] ?? [] as $result) {
                $results[] = array_map(static fn (array $v) => ['id' => (string) $v['id'], 'modified' => (string) ($v['modified'] ?? '')], $result['vulns'] ?? []);
            }
        }

        return $results;
    }

    /**
     * Fiches complètes, téléchargées seulement si absentes du cache ou modifiées depuis.
     *
     * @param array<string, string> $wanted identifiant => date de modification
     *
     * @return array<string, array<string, mixed>>
     */
    public function advisories(array $wanted, ?callable $progress = null): array
    {
        $records = [];
        $missing = [];
        foreach ($wanted as $id => $modified) {
            $cached = $this->advisories->find($id);
            if ($cached && $cached->getModified() === $modified) {
                $records[$id] = $cached->getData();
            } else {
                $missing[$id] = $modified;
            }
        }

        $done = 0;
        foreach (array_chunk(array_keys($missing), 20) as $chunk) {
            $responses = [];
            foreach ($chunk as $id) {
                $responses[$id] = $this->http->request('GET', 'https://api.osv.dev/v1/vulns/'.rawurlencode($id), ['timeout' => 30]);
            }
            foreach ($responses as $id => $response) {
                if (200 !== $response->getStatusCode()) {
                    continue;
                }
                $data = $response->toArray();
                $records[$id] = $data;
                $entity = $this->advisories->find($id) ?? new Advisory($id);
                $entity->update((string) ($data['modified'] ?? $missing[$id]), $data);
                $this->em->persist($entity);
            }
            $done += \count($chunk);
            if ($progress) {
                $progress($done, \count($missing));
            }
        }
        $this->em->flush();

        return $records;
    }
}
