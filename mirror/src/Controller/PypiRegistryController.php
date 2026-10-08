<?php

namespace App\Controller;

use App\Entity\Package;
use App\Registry\Files;
use App\Repository\PackageRepository;
use Doctrine\DBAL\Connection;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Index Python « simple » (PEP 503 en HTML, PEP 691 en JSON). Configuration côté client :
 *   pip config set global.index-url http://miroir/pypi/simple/
 */
final class PypiRegistryController
{
    private const string JSON = 'application/vnd.pypi.simple.v1+json';

    public function __construct(
        private readonly PackageRepository $packages,
        private readonly Connection $db,
        private readonly Files $files,
    ) {
    }

    #[Route('/pypi/simple/', methods: ['GET', 'HEAD'])]
    public function index(Request $request): Response
    {
        $names = $this->db->fetchFirstColumn("SELECT DISTINCT name FROM package WHERE ecosystem = 'pypi' ORDER BY name");

        if (self::wantsJson($request)) {
            return new JsonResponse(['meta' => ['api-version' => '1.1'], 'projects' => array_map(static fn ($n) => ['name' => $n], $names)], 200, ['Content-Type' => self::JSON]);
        }
        $links = array_map(static fn ($n) => sprintf('<a href="%1$s/">%1$s</a>', htmlspecialchars($n)), $names);

        return self::html('Simple index', implode("\n", $links));
    }

    // « /pypi/simple/nom » sans barre finale : redirection automatique du routeur Symfony
    #[Route('/pypi/simple/{name}/', methods: ['GET', 'HEAD'], requirements: ['name' => '[^/]+'])]
    public function project(Request $request, string $name): Response
    {
        $normalized = self::normalize($name);
        if ($normalized !== $name) {
            return new RedirectResponse('/pypi/simple/'.$normalized.'/', 301);
        }
        /** @var list<Package> $files */
        $files = $this->packages->findBy(['ecosystem' => Ecosystem::Pypi, 'name' => $name], ['version' => 'ASC', 'filename' => 'ASC']);
        if ([] === $files) {
            return new Response('Projet absent du miroir hors ligne.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        usort($files, static fn (Package $a, Package $b) => version_compare($a->getVersion(), $b->getVersion()) ?: strcmp((string) $a->getFilename(), (string) $b->getFilename()));

        $base = $this->files->baseUrl($request);
        $entries = [];
        foreach ($files as $file) {
            $sha = $file->getBlobs()[0];
            $entries[] = [
                'filename' => (string) $file->getFilename(),
                'url' => sprintf('%s/pypi/files/%s/%s', $base, $sha, rawurlencode((string) $file->getFilename())),
                'hashes' => ['sha256' => $sha],
                'requires-python' => $file->getMetadata()['requiresPython'] ?? null,
                'size' => $file->getSize(),
            ];
        }

        if (self::wantsJson($request)) {
            return new JsonResponse([
                'meta' => ['api-version' => '1.1'],
                'name' => $name,
                'versions' => array_values(array_unique(array_map(static fn (Package $p) => $p->getVersion(), $files))),
                'files' => array_map(static fn (array $e) => array_filter($e, static fn ($v) => null !== $v), $entries),
            ], 200, ['Content-Type' => self::JSON]);
        }

        $links = array_map(static fn (array $e) => sprintf(
            '<a href="%s#sha256=%s"%s>%s</a><br>',
            htmlspecialchars($e['url']),
            $e['hashes']['sha256'],
            $e['requires-python'] ? ' data-requires-python="'.htmlspecialchars($e['requires-python']).'"' : '',
            htmlspecialchars($e['filename']),
        ), $entries);

        return self::html('Links for '.$name, sprintf("<h1>Links for %s</h1>\n%s", htmlspecialchars($name), implode("\n", $links)));
    }

    #[Route('/pypi/files/{sha256}/{filename}', methods: ['GET', 'HEAD'], requirements: ['sha256' => '[a-f0-9]{64}', 'filename' => '[^/]+'])]
    public function file(string $sha256): Response
    {
        return $this->files->serve($sha256);
    }

    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', $name));
    }

    private static function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), self::JSON) || 'json' === $request->query->get('format');
    }

    private static function html(string $title, string $body): Response
    {
        return new Response(
            sprintf("<!DOCTYPE html>\n<html><head><meta name=\"pypi:repository-version\" content=\"1.1\"><title>%s</title></head><body>\n%s\n</body></html>\n", htmlspecialchars($title), $body),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
