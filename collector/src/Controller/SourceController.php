<?php

namespace App\Controller;

use App\Dto\SourceInput;
use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Source\SecretBox;
use App\Source\Sources;
use App\Source\SourceTester;
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

/** Dépôts sources privés, utilisés en plus des dépôts officiels (ou à leur place). */
#[Route('/api/sources')]
final class SourceController
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $box,
        private readonly SourceTester $tester,
        private readonly Sources $active,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(#[\Symfony\Component\HttpKernel\Attribute\MapQueryString(validationFailedStatusCode: 422)] \App\Dto\PageQuery $page = new \App\Dto\PageQuery()): JsonResponse
    {
        return new JsonResponse($page->envelope(
            array_map(Presenter::source(...), $this->sources->findBy([], ['ecosystem' => 'ASC', 'id' => 'ASC'], $page->pageSize, $page->offset())),
            $this->sources->count([]),
        ));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] SourceInput $input): JsonResponse
    {
        $source = new Source(Ecosystem::from($input->ecosystem));
        if (null !== $error = $this->apply($source, $input)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->persist($source);
        $this->em->flush();

        return new JsonResponse(Presenter::source($source), Response::HTTP_CREATED);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID_V7])]
    public function update(Source $source, #[MapRequestPayload] SourceInput $input): JsonResponse
    {
        if ($input->ecosystem !== $source->getEcosystem()->value) {
            return new JsonResponse(['error' => 'L\'écosystème d\'un dépôt ne peut pas changer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null !== $error = $this->apply($source, $input)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->flush();

        return new JsonResponse(Presenter::source($source));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID_V7])]
    public function delete(Source $source): JsonResponse
    {
        $this->em->remove($source);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Teste un dépôt sans l'enregistrer ({id} pour réutiliser le secret déjà enregistré). */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/test', methods: ['POST'])]
    public function test(#[MapRequestPayload] SourceInput $input): JsonResponse
    {
        $stored = null !== $input->id ? $this->sources->find($input->id) : null;
        $source = new Source(Ecosystem::from($input->ecosystem));
        if ($stored && null !== $stored->getSecret()) {
            $source->setAuth($stored->getAuthType(), $stored->getUsername(), $stored->getSecret());
        }
        if (null !== $error = $this->apply($source, $input, $stored, false)) {
            return new JsonResponse(['ok' => false, 'message' => $error]);
        }

        return new JsonResponse($this->tester->test($source));
    }

    private function apply(Source $source, SourceInput $input, ?Source $self = null, bool $checkConflicts = true): ?string
    {
        $self ??= $source;
        $ecosystem = $source->getEcosystem();
        $url = trim($input->url);
        if (\in_array($ecosystem, [Ecosystem::Deb, Ecosystem::Rpm], true) && null !== $error = $this->system($source, $input, $url)) {
            return $error;
        }
        // Docker : l'hôte du registre suffit (harbor.local:5000)
        if (Ecosystem::Docker === $ecosystem && !preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (!preg_match('#^https?://[^/\s]+#i', $url) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            return 'Adresse invalide : attendu http(s)://hôte/chemin.';
        }
        if (Ecosystem::Docker === $ecosystem && '' !== trim((string) parse_url($url, \PHP_URL_PATH), '/')) {
            return 'Registre Docker : indiquez seulement l\'hôte (ex. harbor.example.com ou registry.local:5000).';
        }
        if (Ecosystem::Pypi === $ecosystem && !preg_match('#/simple/?$#', $url)) {
            return 'Index Python : indiquez l\'adresse de l\'index « simple » (se termine par /simple, ex. https://pypi.example.com/simple).';
        }
        $scope = Ecosystem::Npm === $ecosystem ? (trim((string) $input->scope) ?: null) : null;
        if (null !== $scope && !preg_match('/^@[a-z0-9][a-z0-9._-]*$/', $scope = strtolower($scope))) {
            return 'Portée npm invalide (ex. @entreprise).';
        }
        // Debian, RPM : un dépôt tiers s'ajoute toujours aux dépôts officiels de sa distribution
        $replaces = $input->replacesDefault && !(Ecosystem::Npm === $ecosystem && null !== $scope) && !\in_array($ecosystem, [Ecosystem::Deb, Ecosystem::Rpm], true);
        if (Ecosystem::Npm === $ecosystem && null === $scope && !$replaces) {
            return 'npm : indiquez la portée servie par ce dépôt (@entreprise), ou cochez « remplace le dépôt officiel » (Verdaccio, Nexus… qui relaient aussi npmjs).';
        }
        if ($checkConflicts && $replaces && $input->enabled) {
            foreach ($this->sources->findBy(['ecosystem' => $ecosystem, 'enabled' => true, 'replacesDefault' => true]) as $other) {
                if ($other !== $self && $other->getId() !== $self->getId()) {
                    return sprintf('« %s » remplace déjà le dépôt officiel : un seul dépôt peut le faire.', $other->getLabel());
                }
            }
        }
        if ($checkConflicts && null !== $scope && $input->enabled) {
            foreach ($this->sources->findBy(['ecosystem' => $ecosystem, 'enabled' => true, 'scope' => $scope]) as $other) {
                if ($other !== $self && $other->getId() !== $self->getId()) {
                    return sprintf('La portée %s est déjà servie par « %s ».', $scope, $other->getLabel());
                }
            }
        }

        $secret = trim((string) $input->secret);
        if (Source::AUTH_NONE !== $input->authType && '' === $secret && null === $source->getSecret()) {
            return Source::AUTH_TOKEN === $input->authType ? 'Saisissez le jeton d\'accès.' : 'Saisissez le mot de passe.';
        }
        if (Source::AUTH_BASIC === $input->authType && '' === trim((string) $input->username)) {
            return 'Saisissez l\'identifiant.';
        }

        $source->update($input->label, $url, $scope, $replaces, $input->enabled);
        $source->setAuth($input->authType, $input->username, '' === $secret ? null : $this->box->seal($secret));
        $this->active->reset();

        return null;
    }

    /** Debian, RPM : distribution de rattachement, suite et composants (apt), clé de signature. */
    private function system(Source $source, SourceInput $input, string $url): ?string
    {
        $ecosystem = $source->getEcosystem();
        $distribution = (string) $input->distribution;
        if (!\array_key_exists($distribution, Distributions::of($ecosystem))) {
            return 'Choisissez la distribution à laquelle ajouter ce dépôt.';
        }
        $suite = null;
        $components = [];
        if (Ecosystem::Deb === $ecosystem) {
            $suite = trim((string) $input->suite);
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $suite)) {
                return 'Suite invalide : celle de la ligne « deb » du dépôt (ex. bookworm, bookworm-pgdg, stable).';
            }
            $components = array_values(array_unique(preg_split('/[\s,]+/', trim((string) $input->components), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
            if ([] === $components || array_filter($components, static fn (string $c) => !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $c))) {
                return 'Composants invalides : ceux de la ligne « deb » du dépôt, séparés par des espaces (ex. main, stable).';
            }
        } elseif (preg_match('#\$(?!basearch\b|arch\b)\w+#', $url)) {
            return 'Adresse dnf : seule la variable $basearch est remplacée (x86_64, aarch64) ; remplacez $releasever par le numéro de version.';
        }
        $key = trim((string) $input->signingKey);
        if ('' === $key) {
            $key = (string) $source->getSigningKey();
        }
        if (null === OpenPgpKey::fingerprint($key)) {
            return 'Clé de signature invalide : collez la clé publique OpenPGP du dépôt (bloc « BEGIN PGP PUBLIC KEY BLOCK »).';
        }
        $source->setSystem($distribution, $suite, $components, $key);

        return null;
    }
}
