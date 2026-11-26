<?php

namespace App\Registry;

use Sas\Bundle\BlobStore;
use Sas\Bundle\LocalBlobBackend;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Réponses communes aux registres : fichiers du stockage et adresse publique du miroir. */
final readonly class Files
{
    public function __construct(
        private BlobStore $store,
        private string $publicUrl,
        private RequestStack $requests,
    ) {
    }

    /** Adresse de base du miroir telle que vue par les clients (PUBLIC_URL, sinon l'hôte de la requête). */
    public function baseUrl(Request $request): string
    {
        return '' !== $this->publicUrl ? rtrim($this->publicUrl, '/') : $request->getSchemeAndHttpHost();
    }

    /**
     * Fichier du stockage, immuable (son adresse contient son empreinte). Les requêtes Range sont gérées :
     * par BinaryFileResponse sur disque, par lecture partielle sur un bucket S3 (Romulus relaie, les postes
     * n'ont pas besoin d'accéder au bucket).
     */
    public function serve(string $sha256, string $contentType = 'application/octet-stream'): Response
    {
        if (!$this->store->has($sha256)) {
            throw new NotFoundHttpException('Fichier absent du miroir.');
        }
        set_time_limit(0);
        if ($this->store->backend() instanceof LocalBlobBackend) {
            $response = new BinaryFileResponse($this->store->path($sha256), 200, ['Content-Type' => $contentType], false, null, false, false);
        } else {
            $response = $this->streamed($sha256, $contentType);
        }
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setEtag($sha256);

        return $response;
    }

    /** Lecture depuis le bucket, avec une plage d'octets unique si demandée (reprise de téléchargement). */
    private function streamed(string $sha256, string $contentType): Response
    {
        $size = $this->store->size($sha256);
        $request = $this->requests->getCurrentRequest();
        [$status, $offset, $length] = [200, 0, $size];
        $range = (string) $request?->headers->get('Range');
        if ('HEAD' !== $request?->getMethod() && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ('' !== $m[1] || '' !== $m[2])) {
            $start = '' === $m[1] ? max(0, $size - (int) $m[2]) : (int) $m[1];
            $end = '' === $m[1] || '' === $m[2] ? $size - 1 : min((int) $m[2], $size - 1);
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => 'bytes */'.$size]);
            }
            [$status, $offset, $length] = [206, $start, $end - $start + 1];
        }
        $store = $this->store;
        $response = new StreamedResponse(static function () use ($store, $sha256, $offset, $length): void {
            $in = $store->stream($sha256, $offset, $length);
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('Sortie indisponible.');
            stream_copy_to_stream($in, $out, $length);
            fclose($in);
            fclose($out);
        }, $status, ['Content-Type' => $contentType, 'Content-Length' => (string) $length, 'Accept-Ranges' => 'bytes']);
        if (206 === $status) {
            $response->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size));
        }

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
