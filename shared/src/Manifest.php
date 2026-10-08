<?php

namespace Sas\Bundle;

/**
 * Contenu d'un bundle : la liste des paquets (artefacts) et des fichiers (blobs) qu'il transporte.
 *
 * La clé publique du collecteur y figure pour que le miroir puisse l'afficher lors du premier import ;
 * elle n'est jamais approuvée automatiquement.
 *
 * Un artefact peut référencer des blobs absents du bundle lorsqu'ils ont été transportés par un bundle
 * précédent (export incrémental) : le miroir vérifie qu'il les possède déjà.
 *
 * Forme d'un artefact :
 *   { ecosystem, name, version, filename?, blobs: [sha256…], metadata: {…} }
 */
final readonly class Manifest
{
    public const string FORMAT = 'sas-bundle/1';

    /**
     * @param list<array{ecosystem: string, name: string, version: string, filename?: string|null, blobs: list<string>, metadata?: array<string, mixed>}> $artifacts
     * @param list<array{sha256: string, size: int}>                                                                                                $blobs
     */
    public function __construct(
        public string $id,
        public string $collectorId,
        public string $collectorName,
        public string $collectorPublicKey,
        public int $sequence,
        public \DateTimeImmutable $createdAt,
        public bool $full,
        public array $artifacts,
        public array $blobs,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'id' => $this->id,
            'collector' => ['id' => $this->collectorId, 'name' => $this->collectorName, 'publicKey' => $this->collectorPublicKey],
            'sequence' => $this->sequence,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'full' => $this->full,
            'artifacts' => $this->artifacts,
            'blobs' => $this->blobs,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new IntegrityException('Manifeste illisible.');
        }
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new IntegrityException(sprintf('Format de bundle non pris en charge : %s.', \is_array($data) ? json_encode($data['format'] ?? null) : '?'));
        }

        $artifacts = [];
        foreach ($data['artifacts'] ?? [] as $i => $a) {
            if (!\is_array($a) || !\is_string($a['name'] ?? null) || !\is_string($a['version'] ?? null)
                || null === Ecosystem::tryFrom((string) ($a['ecosystem'] ?? '')) || !\is_array($a['blobs'] ?? null)) {
                throw new IntegrityException(sprintf('Artefact n°%d invalide dans le manifeste.', $i + 1));
            }
            foreach ($a['blobs'] as $sha) {
                if (!\is_string($sha) || !BlobStore::isDigest($sha)) {
                    throw new IntegrityException(sprintf('Empreinte invalide pour %s.', $a['name']));
                }
            }
            $artifacts[] = $a;
        }

        $blobs = [];
        foreach ($data['blobs'] ?? [] as $b) {
            if (!\is_array($b) || !\is_string($b['sha256'] ?? null) || !BlobStore::isDigest($b['sha256']) || !\is_int($b['size'] ?? null)) {
                throw new IntegrityException('Liste des fichiers du manifeste invalide.');
            }
            $blobs[] = ['sha256' => $b['sha256'], 'size' => $b['size']];
        }

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['collector']['id'] ?? ''),
            (string) ($data['collector']['name'] ?? ''),
            (string) ($data['collector']['publicKey'] ?? ''),
            (int) ($data['sequence'] ?? 0),
            new \DateTimeImmutable((string) ($data['createdAt'] ?? 'now')),
            (bool) ($data['full'] ?? false),
            $artifacts,
            $blobs,
        );
    }

    public function totalSize(): int
    {
        return array_sum(array_column($this->blobs, 'size'));
    }
}
