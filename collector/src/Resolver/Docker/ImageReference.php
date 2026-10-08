<?php

namespace App\Resolver\Docker;

/**
 * Référence d'image normalisée comme le fait Docker :
 *   nginx                   -> docker.io/library/nginx:latest
 *   ghcr.io/owner/app:1.2   -> ghcr.io/owner/app:1.2
 *   alpine@sha256:…         -> docker.io/library/alpine@sha256:…
 */
final readonly class ImageReference
{
    private function __construct(
        public string $registry,
        public string $repository,
        public string $reference,
    ) {
    }

    public static function parse(string $input): self
    {
        $input = trim($input);
        $reference = 'latest';
        if (preg_match('/^(.+)@(sha256:[a-f0-9]{64})$/', $input, $m)) {
            [$input, $reference] = [$m[1], $m[2]];
            // Une étiquette avant le digest est ignorée, comme le fait Docker
            $input = (string) preg_replace('/:[\w][\w.-]{0,127}$/', '', $input);
        } elseif (preg_match('/^(.+):([\w][\w.-]{0,127})$/', $input, $m) && !str_contains($m[2], '/')) {
            [$input, $reference] = [$m[1], $m[2]];
        }

        $parts = explode('/', $input, 2);
        if (2 === \count($parts) && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || 'localhost' === $parts[0])) {
            [$registry, $repository] = $parts;
        } else {
            [$registry, $repository] = ['docker.io', $input];
        }
        if ('docker.io' === $registry && !str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }
        if (!preg_match('#^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$#', $repository)) {
            throw new \InvalidArgumentException(sprintf('« %s » : nom d\'image invalide.', $input));
        }

        return new self($registry, $repository, $reference);
    }

    public function isDigest(): bool
    {
        return str_starts_with($this->reference, 'sha256:');
    }

    /** Adresse HTTPS de l'API du registre. */
    public function endpoint(): string
    {
        return 'docker.io' === $this->registry ? 'https://registry-1.docker.io' : 'https://'.$this->registry;
    }

    /**
     * Nom servi par le miroir : les images Docker Hub gardent leur nom (library/nginx, ce qui permet
     * d'utiliser le miroir comme « registry-mirror »), les autres sont préfixées par leur registre.
     */
    public function mirrorName(): string
    {
        return 'docker.io' === $this->registry ? $this->repository : $this->registry.'/'.$this->repository;
    }

    public function __toString(): string
    {
        return sprintf('%s/%s%s%s', $this->registry, $this->repository, $this->isDigest() ? '@' : ':', $this->reference);
    }
}
