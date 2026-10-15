<?php

namespace App\Controller;

use App\Http\DownloadException;
use App\Search\PackageSearch;
use App\Search\PatternExpander;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Recherche dans les registres publics et aperçu des motifs, pour composer une liste. */
#[Route('/api/search')]
final class SearchController
{
    public function __construct(
        private readonly PackageSearch $search,
        private readonly PatternExpander $expander,
    ) {
    }

    /** ?ecosystem=npm&q=lodash */
    #[Route('', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->search->search($this->ecosystem($request), $request->query->getString('q')));
    }

    /** ?ecosystem=npm&name=lodash */
    #[Route('/versions', methods: ['GET'])]
    public function versions(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->search->versions($this->ecosystem($request), $request->query->getString('name')));
    }

    /** Aperçu d'un motif avant de l'enregistrer : {ecosystem, line} → lignes obtenues */
    #[Route('/expand', methods: ['POST'])]
    public function expand(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $ecosystem = Ecosystem::tryFrom((string) ($data['ecosystem'] ?? '')) ?? Ecosystem::Npm;

        return $this->guard(function () use ($ecosystem, $data) {
            $result = $this->expander->expandLine($ecosystem, (string) ($data['line'] ?? ''));

            return ['lines' => $result['lines'] ?? [], 'skipped' => $result['skipped'] ?? [], 'pattern' => null !== $result];
        });
    }

    private function ecosystem(Request $request): Ecosystem
    {
        return Ecosystem::tryFrom($request->query->getString('ecosystem')) ?? throw new \InvalidArgumentException('Écosystème inconnu.');
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return new JsonResponse($action());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DownloadException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404 === $e->getCode() ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_GATEWAY);
        }
    }
}
