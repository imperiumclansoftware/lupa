<?php

namespace App\Controller;

use App\Blacklist\Blacklist;
use App\Dto\ListInput;
use App\Entity\Artifact;
use App\Entity\Job;
use App\Entity\PackageList;
use App\Job\JobLauncher;
use App\Repository\JobRepository;
use App\Repository\PackageListRepository;
use App\Resolver\DockerResolver;
use App\Resolver\PypiPlatforms;
use App\Resolver\Resolver;
use App\Template\TemplateCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ListController
{
    /** @param iterable<Resolver> $resolvers */
    public function __construct(
        private readonly PackageListRepository $lists,
        private readonly JobRepository $jobs,
        private readonly EntityManagerInterface $em,
        private readonly JobLauncher $launcher,
        #[AutowireIterator('app.resolver')]
        private readonly iterable $resolvers,
        private readonly Blacklist $blacklist,
    ) {
    }

    /** Choix proposés par le formulaire (plateformes, versions de Python…). */
    #[Route('/ecosystems', methods: ['GET'])]
    public function ecosystems(): JsonResponse
    {
        return new JsonResponse([
            'ecosystems' => array_map(static fn (Ecosystem $e) => ['id' => $e->value, 'label' => $e->label()], Ecosystem::cases()),
            'pypi' => [
                'pythonVersions' => PypiPlatforms::PYTHON_VERSIONS,
                'platforms' => self::choices(PypiPlatforms::labels()),
            ],
            'docker' => ['platforms' => self::choices(DockerResolver::PLATFORMS)],
        ]);
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{id: string, label: string}>
     */
    private static function choices(array $labels): array
    {
        return array_map(static fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys($labels), $labels);
    }

    /** Modèles de framework (générés par Rémus ou listes prédéfinies). */
    #[Route('/templates', methods: ['GET'])]
    public function templates(): JsonResponse
    {
        return new JsonResponse(TemplateCatalog::all());
    }

    #[Route('/lists', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $active = $this->activeJobs();

        return new JsonResponse(array_map(
            static fn (PackageList $l) => Presenter::list($l, $active[$l->getId()] ?? null),
            $this->lists->findBy([], ['ecosystem' => 'ASC', 'name' => 'ASC']),
        ));
    }

    #[Route('/lists/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(PackageList $list): JsonResponse
    {
        $job = $this->jobs->findOneBy(['list' => $list], ['id' => 'DESC']);

        return new JsonResponse(Presenter::list($list, $job, true));
    }

    #[Route('/lists', methods: ['POST'])]
    public function create(#[MapRequestPayload] ListInput $input): JsonResponse
    {
        $list = new PackageList($input->name, Ecosystem::from($input->ecosystem));

        return $this->save($list, $input, Response::HTTP_CREATED);
    }

    #[Route('/lists/{id}', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(PackageList $list, #[MapRequestPayload] ListInput $input): JsonResponse
    {
        if ($input->ecosystem !== $list->getEcosystem()->value) {
            return new JsonResponse(['error' => 'L\'écosystème d\'une liste ne peut pas changer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->save($list, $input, Response::HTTP_OK);
    }

    #[Route('/lists/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(PackageList $list): Response
    {
        // Les paquets téléchargés restent dans le collecteur (ils peuvent servir à d'autres listes)
        $this->em->remove($list);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/lists/{id}/resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolve(PackageList $list): JsonResponse
    {
        if ($errors = $this->resolver($list)->validate($list)) {
            return new JsonResponse(['error' => implode("\n", $errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $job = $this->launcher->launch('resolve', sprintf('Mise à jour de « %s »', $list->getName()), $list);

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    #[Route('/lists/{id}/artifacts', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function artifacts(PackageList $list): JsonResponse
    {
        $artifacts = $list->getArtifacts()->toArray();
        usort($artifacts, static fn (Artifact $a, Artifact $b) => [$a->getName(), $a->getVersion(), $a->getFilename()] <=> [$b->getName(), $b->getVersion(), $b->getFilename()]);

        return new JsonResponse(array_map(fn (Artifact $a) => Presenter::artifact($a, null !== $a->getSeverity(), $this->blacklist->find($a->getEcosystem(), $a->getName(), $a->getVersion())), $artifacts));
    }

    private function save(PackageList $list, ListInput $input, int $status): JsonResponse
    {
        $list->setName(trim($input->name));
        $list->setSpecs($input->specs);
        $list->setOptions($this->options($list->getEcosystem(), $input->options));
        if ($input->removeLockfile) {
            $list->setLockfile(null, null);
        }
        if (null !== $input->lockfileContent && '' !== trim($input->lockfileContent)) {
            $list->setLockfile(basename($input->lockfileName ?? 'lockfile'), $input->lockfileContent);
        }

        if ($errors = $this->resolver($list)->validate($list)) {
            return new JsonResponse(['error' => implode("\n", $errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->persist($list);
        $this->em->flush();

        return new JsonResponse(Presenter::list($list, null, true), $status);
    }

    /**
     * Options retenues selon l'écosystème (le reste est ignoré).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function options(Ecosystem $ecosystem, array $options): array
    {
        return match ($ecosystem) {
            Ecosystem::Npm => [
                'includeDev' => (bool) ($options['includeDev'] ?? true),
                'legacyPeerDeps' => (bool) ($options['legacyPeerDeps'] ?? false),
                'template' => self::template($options),
            ],
            Ecosystem::Pypi => [
                'targets' => array_values(array_map(
                    static fn ($t) => ['python' => (string) ($t['python'] ?? ''), 'platform' => (string) ($t['platform'] ?? '')],
                    array_filter((array) ($options['targets'] ?? []), 'is_array'),
                )),
                'sdist' => (bool) ($options['sdist'] ?? false),
            ],
            Ecosystem::Composer => [
                'includeDev' => (bool) ($options['includeDev'] ?? false),
                'php' => isset($options['php']) && '' !== $options['php'] ? (string) $options['php'] : null,
                'template' => self::template($options),
            ],
            Ecosystem::Docker => [
                'platforms' => array_values(array_filter((array) ($options['platforms'] ?? []), 'is_string')),
            ],
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{id: string, version: string}|null
     */
    private static function template(array $options): ?array
    {
        $template = $options['template'] ?? null;
        if (!\is_array($template) || !isset($template['id'])) {
            return null;
        }

        return ['id' => (string) $template['id'], 'version' => trim((string) ($template['version'] ?? 'latest')) ?: 'latest'];
    }

    private function resolver(PackageList $list): Resolver
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->ecosystem() === $list->getEcosystem()) {
                return $resolver;
            }
        }
        throw new \LogicException('Résolveur manquant.');
    }

    /** @return array<int, Job> tâche en cours par liste */
    private function activeJobs(): array
    {
        $active = [];
        foreach ($this->jobs->findBy(['status' => [Job::QUEUED, Job::RUNNING]]) as $job) {
            if ($job->getList()) {
                $active[$job->getList()->getId()] = $job;
            }
        }

        return $active;
    }
}
