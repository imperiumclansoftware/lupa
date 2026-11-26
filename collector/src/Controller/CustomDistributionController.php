<?php

namespace App\Controller;

use App\Dto\CustomDistributionInput;
use App\Entity\CustomDistribution;
use App\Repository\CustomDistributionRepository;
use App\Repository\PackageListRepository;
use App\Repository\SourceRepository;
use App\System\CustomDistributions;
use App\System\DebIndex;
use App\System\IndexFiles;
use App\System\RpmIndex;
use App\System\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\OpenPgpKey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Réglages › Distributions personnalisées : dérivées internes, distributions absentes du catalogue. */
#[Route('/api/custom-distributions')]
final class CustomDistributionController
{
    public function __construct(
        private readonly CustomDistributionRepository $distributions,
        private readonly PackageListRepository $lists,
        private readonly SourceRepository $sources,
        private readonly EntityManagerInterface $em,
        private readonly CustomDistributions $catalog,
        private readonly DebIndex $deb,
        private readonly IndexFiles $files,
        private readonly Signatures $signatures,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['items' => array_map($this->present(...), $this->distributions->findBy([], ['slug' => 'ASC']))]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] CustomDistributionInput $input): JsonResponse
    {
        if (Distributions::isBuiltIn($input->slug) || null !== $this->distributions->findOneBy(['slug' => $input->slug])) {
            return self::error(sprintf('L\'identifiant « %s » est déjà utilisé.', $input->slug));
        }
        $distribution = new CustomDistribution($input->slug, Ecosystem::from($input->ecosystem));
        if (null !== $error = $this->apply($distribution, $input)) {
            return self::error($error);
        }
        $this->em->persist($distribution);
        $this->em->flush();
        $this->catalog->reset();

        return new JsonResponse($this->present($distribution), Response::HTTP_CREATED);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID_V7])]
    public function update(CustomDistribution $distribution, #[MapRequestPayload] CustomDistributionInput $input): JsonResponse
    {
        if ($input->slug !== $distribution->getSlug() || $input->ecosystem !== $distribution->getEcosystem()->value) {
            return self::error('L\'identifiant et le type d\'une distribution ne peuvent pas changer : ils figurent dans les chemins des paquets.');
        }
        if (null !== $error = $this->apply($distribution, $input)) {
            return self::error($error);
        }
        $this->em->flush();
        $this->catalog->reset();

        return new JsonResponse($this->present($distribution));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID_V7])]
    public function delete(CustomDistribution $distribution): JsonResponse
    {
        [$lists, $sources] = $this->usage($distribution);
        if ($lists + $sources > 0) {
            return new JsonResponse(['error' => sprintf('Distribution utilisée par %d liste(s) et %d dépôt(s) tiers : supprimez-les ou changez leur distribution d\'abord.', $lists, $sources)], Response::HTTP_CONFLICT);
        }
        $this->em->remove($distribution);
        $this->em->flush();
        $this->catalog->reset();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Vérifie chaque suite ou dépôt (signature avec la clé saisie), sans rien enregistrer. */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/test', methods: ['POST'])]
    public function test(#[MapRequestPayload] CustomDistributionInput $input): JsonResponse
    {
        $stored = null !== $input->id ? $this->distributions->find($input->id) : null;
        $distribution = new CustomDistribution($input->slug, Ecosystem::from($input->ecosystem));
        if ($stored) {
            $distribution->update($stored->getLabel(), $stored->getRelease(), $stored->getCodename(), $stored->isEol(), [], [], [], $stored->getSigningKey());
        }
        if (null !== $error = $this->apply($distribution, $input)) {
            return new JsonResponse(['ok' => false, 'message' => $error]);
        }
        $dist = $distribution->toDistribution();
        $arch = (string) array_key_first($dist['architectures']);
        $lines = [];
        try {
            foreach ($dist['suites'] ?? [] as $suite) {
                $release = $this->deb->release($suite['url'], $suite['suite'], $distribution->getSigningKey(), $suite['suite']);
                $components = preg_match('/^Components:[ \t]*(.*)$/m', $release, $m) ? (preg_split('/\s+/', trim($m[1]), -1, \PREG_SPLIT_NO_EMPTY) ?: []) : [];
                $missing = [] === $components ? [] : array_diff($distribution->getSections(), $components);
                $lines[] = [] === $missing
                    ? sprintf('%s : index signé vérifié.', $suite['suite'])
                    : sprintf('%s : index signé, mais composant(s) absent(s) : %s.', $suite['suite'], implode(', ', $missing));
            }
            foreach ($dist['repos'] ?? [] as $name => $repo) {
                $base = str_replace(['$basearch', '$arch'], RpmIndex::packageArch($arch), $repo['url']);
                $repomd = (string) $this->files->text($base.'/repodata/repomd.xml');
                $signature = $this->files->text($base.'/repodata/repomd.xml.asc', true);
                if (null === $signature) {
                    $lines[] = sprintf('%s (%s) : index non signé, chaque paquet sera vérifié avec la clé.', $name, $arch);
                } else {
                    $this->signatures->detachedWithKey($repomd, $signature, $distribution->getSigningKey(), $name);
                    $lines[] = sprintf('%s (%s) : index signé vérifié.', $name, $arch);
                }
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'message' => implode("\n", [...$lines, $e->getMessage()])]);
        }

        return new JsonResponse(['ok' => !str_contains(implode('', $lines), 'absent'), 'message' => implode("\n", $lines)]);
    }

    private function apply(CustomDistribution $distribution, CustomDistributionInput $input): ?string
    {
        $deb = Ecosystem::Deb === $distribution->getEcosystem();
        $words = static fn (?string $text): array => array_values(array_unique(preg_split('/[\s,]+/', trim((string) $text), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
        $architectures = $words($input->architectures);
        if ([] === $architectures || array_filter($architectures, static fn (string $a) => !preg_match('/^[a-z0-9_]+$/', $a))) {
            return $deb ? 'Architectures invalides (ex. amd64 arm64).' : 'Architectures invalides (ex. x86_64 aarch64).';
        }
        $codename = trim((string) $input->codename);
        if ($deb && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $codename)) {
            return 'Nom de code invalide : minuscules, chiffres et tirets (ex. bookworm).';
        }
        $sources = [];
        $sections = $deb ? $words($input->sections) : [];
        if ($deb && ([] === $sections || array_filter($sections, static fn (string $c) => !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $c)))) {
            return 'Composants invalides (ex. main contrib).';
        }
        foreach (preg_split('/\R/', trim($input->sources)) ?: [] as $number => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            [$url, $other] = $deb ? [$parts[0], $parts[1] ?? ''] : [$parts[1] ?? '', $parts[0]];
            if (2 !== \count($parts) || !preg_match('#^https?://[^/\s]+#i', $url) || false === filter_var(str_replace('$', 'x', $url), \FILTER_VALIDATE_URL)
                || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $other)) {
                return sprintf('Ligne %d invalide : attendu %s.', $number + 1, $deb ? '« adresse suite » (ex. https://apt.example.com/debian bookworm)' : '« nom adresse » (ex. BaseOS https://rpm.example.com/9/BaseOS/$basearch/os)');
            }
            $sources[] = $deb ? ['url' => rtrim($url, '/'), 'suite' => $other] : ['name' => $other, 'url' => rtrim($url, '/')];
        }
        if ([] === $sources) {
            return $deb ? 'Ajoutez au moins une suite (« adresse suite » par ligne).' : 'Ajoutez au moins un dépôt (« nom adresse » par ligne).';
        }
        if (!$deb) {
            $sections = array_column($sources, 'name');
            if (\count($sections) !== \count(array_unique($sections))) {
                return 'Deux dépôts portent le même nom.';
            }
        }
        $key = trim((string) $input->signingKey) ?: $distribution->getSigningKey();
        if (null === OpenPgpKey::fingerprint($key)) {
            return 'Clé de signature invalide : collez la clé publique OpenPGP (bloc « BEGIN PGP PUBLIC KEY BLOCK »).';
        }
        $distribution->update(trim($input->label), $input->release, $deb ? $codename : $input->release, $input->eol, $architectures, $sections, $sources, $key);

        return null;
    }

    /** @return array{int, int} listes et dépôts tiers qui utilisent la distribution */
    private function usage(CustomDistribution $distribution): array
    {
        $lists = array_filter(
            $this->lists->findBy(['ecosystem' => $distribution->getEcosystem()]),
            static fn ($list) => ($list->getOptions()['distribution'] ?? null) === $distribution->getSlug(),
        );

        return [\count($lists), \count($this->sources->findBy(['distribution' => $distribution->getSlug()]))];
    }

    /** @return array<string, mixed> */
    private function present(CustomDistribution $distribution): array
    {
        [$lists, $sources] = $this->usage($distribution);
        $deb = Ecosystem::Deb === $distribution->getEcosystem();

        return [
            'id' => $distribution->getId(),
            'slug' => $distribution->getSlug(),
            'ecosystem' => $distribution->getEcosystem()->value,
            'label' => $distribution->getLabel(),
            'release' => $distribution->getRelease(),
            'codename' => $deb ? $distribution->getCodename() : null,
            'eol' => $distribution->isEol(),
            'architectures' => $distribution->getArchitectures(),
            'sections' => $deb ? $distribution->getSections() : [],
            // Texte du formulaire : une suite ou un dépôt par ligne
            'sources' => implode("\n", array_map(static fn (array $s) => $deb ? $s['url'].' '.($s['suite'] ?? '') : ($s['name'] ?? '').' '.$s['url'], $distribution->getSources())),
            'keyFingerprint' => OpenPgpKey::fingerprint($distribution->getSigningKey()),
            'usedBy' => ['lists' => $lists, 'sources' => $sources],
            'createdAt' => $distribution->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    private static function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
