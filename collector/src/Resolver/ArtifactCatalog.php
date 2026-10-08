<?php

namespace App\Resolver;

use App\Entity\Artifact;
use App\Entity\Blob;
use App\Repository\ArtifactRepository;
use App\Repository\BlobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;

/** Inventaire des artefacts téléchargés : évite de retélécharger ce qui est déjà dans le stockage. */
final class ArtifactCatalog
{
    /** @var array<string, Artifact> artefacts créés pendant la tâche, pas encore en base */
    private array $pending = [];

    public function __construct(
        private readonly ArtifactRepository $artifacts,
        private readonly BlobRepository $blobs,
        private readonly EntityManagerInterface $em,
        private readonly BlobStore $store,
    ) {
    }

    /** Artefact déjà téléchargé et dont tous les fichiers sont présents. */
    public function complete(Ecosystem $ecosystem, string $name, string $version, ?string $filename = null): ?Artifact
    {
        $artifact = $this->find($ecosystem, $name, $version, $filename);
        if (!$artifact) {
            return null;
        }
        foreach ($artifact->getBlobs() as $sha) {
            if (!$this->store->has($sha)) {
                return null;
            }
        }

        return $artifact;
    }

    public function find(Ecosystem $ecosystem, string $name, string $version, ?string $filename = null): ?Artifact
    {
        $identity = Artifact::identity($ecosystem, $name, $version, $filename);

        return $this->pending[$identity] ?? $this->artifacts->findOneBy(['identity' => $identity]);
    }

    /**
     * Enregistre (ou met à jour) un artefact et ses fichiers.
     *
     * @param list<array{sha256: string, size: int}> $blobs
     * @param array<string, mixed>                   $metadata
     */
    public function record(Ecosystem $ecosystem, string $name, string $version, ?string $filename, array $blobs, array $metadata): Artifact
    {
        $artifact = $this->find($ecosystem, $name, $version, $filename);
        if (!$artifact) {
            $artifact = new Artifact($ecosystem, $name, $version, $filename);
            $this->em->persist($artifact);
            $this->pending[Artifact::identity($ecosystem, $name, $version, $filename)] = $artifact;
        }

        $digests = [];
        foreach ($blobs as $blob) {
            if (isset($digests[$blob['sha256']])) {
                continue;
            }
            $digests[$blob['sha256']] = $blob['size'];
            if (!isset($this->pending['blob:'.$blob['sha256']]) && !$this->blobs->find($blob['sha256'])) {
                $entity = new Blob($blob['sha256'], $blob['size']);
                $this->em->persist($entity);
                $this->pending['blob:'.$blob['sha256']] = $artifact;
            }
        }
        $artifact->update(array_keys($digests), array_sum($digests), $metadata);

        return $artifact;
    }

    /** Enregistre en base ce qui a été téléchargé (appelé régulièrement pour ne rien perdre en cas d'échec). */
    public function flush(): void
    {
        $this->em->flush();
        $this->pending = [];
    }
}
