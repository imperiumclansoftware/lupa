<?php

namespace App\Registry;

use Sas\Bundle\BlobStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Réponses communes aux registres : fichiers du stockage et adresse publique du miroir. */
final readonly class Files
{
    public function __construct(
        private BlobStore $store,
        private string $publicUrl,
    ) {
    }

    /** Adresse de base du miroir telle que vue par les clients (PUBLIC_URL, sinon l'hôte de la requête). */
    public function baseUrl(Request $request): string
    {
        return '' !== $this->publicUrl ? rtrim($this->publicUrl, '/') : $request->getSchemeAndHttpHost();
    }

    /** Fichier du stockage, immuable (son adresse contient son empreinte). Les requêtes Range sont gérées. */
    public function serve(string $sha256, string $contentType = 'application/octet-stream'): BinaryFileResponse
    {
        if (!$this->store->has($sha256)) {
            throw new NotFoundHttpException('Fichier absent du miroir.');
        }
        set_time_limit(0);
        $response = new BinaryFileResponse($this->store->path($sha256), 200, ['Content-Type' => $contentType], false, null, false, false);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setEtag($sha256);

        return $response;
    }

    public function read(string $sha256): string
    {
        return $this->store->read($sha256);
    }

    public function has(string $sha256): bool
    {
        return $this->store->has($sha256);
    }
}
