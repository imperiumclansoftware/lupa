<?php

namespace Sas\Bundle;

/**
 * Moteur du stockage par empreinte : disque local ou bucket S3. Les clés ont la forme « sha256/ab/<sha256> »
 * (et « corrupt/… » pour les fichiers mis de côté par la vérification d'intégrité).
 */
interface BlobBackend
{
    /** « local » ou « s3 » */
    public function name(): string;

    /** Emplacement lisible par un humain (dossier, ou bucket et préfixe) */
    public function describe(): string;

    public function has(string $key): bool;

    public function size(string $key): int;

    public function read(string $key): string;

    /**
     * Flux en lecture, éventuellement limité à une plage d'octets (requêtes Range).
     *
     * @return resource
     */
    public function stream(string $key, int $offset = 0, ?int $length = null);

    /** Range un fichier local (il est consommé : déplacé ou supprimé), d'empreinte déjà vérifiée. */
    public function store(string $file, string $key, string $sha256): void;

    public function delete(string $key): void;

    /** Déplace un fichier (mise de côté d'un fichier abîmé). */
    public function move(string $from, string $to): void;

    /**
     * Fichier local pour les outils qui en ont besoin (gpgv, tar, zip) ; temporaire pour un bucket.
     *
     * @return array{string, bool} chemin, et vrai s'il faut le supprimer après usage
     */
    public function localFile(string $key): array;

    /**
     * Clés présentes sous un préfixe (migration d'un moteur à l'autre).
     *
     * @return iterable<string>
     */
    public function keys(string $prefix): iterable;
}
