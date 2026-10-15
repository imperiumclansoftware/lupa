<?php

namespace App\Controller;

use App\Blacklist\Blacklist;
use App\Dto\BlacklistInput;
use App\Entity\BlacklistEntry;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** Liste noire : versions interdites de téléchargement, transmises à Romulus qui les met en quarantaine. */
#[Route('/api/blacklist')]
final class BlacklistController
{
    public function __construct(private readonly Blacklist $blacklist)
    {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $collected = [];
        foreach ($this->blacklist->collected() as $entry) {
            $collected[$entry->getId()] = ($collected[$entry->getId()] ?? 0) + 1;
        }

        return new JsonResponse([
            'revision' => $this->blacklist->revision(),
            'exportedRevision' => $this->blacklist->exportedRevision(),
            'pending' => $this->blacklist->pendingExport(),
            'updatedAt' => $this->blacklist->updatedAt(),
            'items' => array_map(static fn (BlacklistEntry $e) => Presenter::blacklistEntry($e) + ['collected' => $collected[$e->getId()] ?? 0], $this->blacklist->all()),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] BlacklistInput $input): JsonResponse
    {
        $ecosystem = Ecosystem::from($input->ecosystem);
        [$name, $version] = [trim($input->name), trim($input->version)];
        if (null !== $error = self::validate($ecosystem, $name, $version)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(Presenter::blacklistEntry($this->blacklist->add($ecosystem, $name, $version, $input->reason)), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(BlacklistEntry $entry): JsonResponse
    {
        $this->blacklist->remove($entry);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Une version précise, jamais un intervalle ni un motif. */
    public static function validate(Ecosystem $ecosystem, string $name, string $version): ?string
    {
        if (preg_match('/[*?\s]/', $name)) {
            return 'Nom de paquet invalide : pas de motif ni d\'espace, la liste noire vise un paquet précis.';
        }
        $exact = match ($ecosystem) {
            Ecosystem::Docker => '/^(sha256:[a-f0-9]{64}|[\w][\w.-]{0,127})$/',
            Ecosystem::Galaxy, Ecosystem::Terraform => '/^v?\d+(\.\d+){0,3}([-+][0-9a-z.]+)?$/i',
            Ecosystem::Composer => '/^v?\d+(\.\d+){0,3}([-.+]?[a-z0-9.]+)?$|^dev-[\w.\/-]+$/i',
            // Debian : [époque:]amont[-révision] ; RPM : [époque:]version-release (release obligatoire)
            Ecosystem::Deb => '/^(\d+:)?\d[A-Za-z0-9.+~-]*$/',
            Ecosystem::Rpm => '/^(\d+:)?[A-Za-z0-9._+~^]+-[A-Za-z0-9._+~^]+$/',
            default => '/^v?\d+(\.\d+)*([-.+_]?[a-z0-9.+]+)?$/i',
        };
        // « ~ » et « ^ » font partie des versions Debian et RPM (1.2~rc1) ; ailleurs ce sont des intervalles
        $ranges = \in_array($ecosystem, [Ecosystem::Deb, Ecosystem::Rpm], true) ? '/[<>=|,*\s]/' : '/(^|\.)[x*](\.|$)|[\^~<>=|,]/i';
        if (!preg_match($exact, $version) || preg_match($ranges, $version) || 'latest' === strtolower($version)) {
            return sprintf('« %s » n\'est pas une version précise (ex. %s) : les intervalles, « latest » et les motifs ne sont pas acceptés.', $version, match ($ecosystem) {
                Ecosystem::Docker => '1.27.3',
                Ecosystem::Pypi => '4.2.1',
                Ecosystem::Deb => '3.0.15-1~deb12u1',
                Ecosystem::Rpm => '1:3.0.7-27.el9 (version-release complète)',
                default => '1.2.3',
            });
        }

        return null;
    }
}
