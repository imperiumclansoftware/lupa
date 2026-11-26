<?php

namespace App\Controller;

use App\Entity\Job;
use App\Job\JobLauncher;
use App\Repository\JobRepository;
use Sas\Bundle\BlobStore;
use Sas\Bundle\StorageSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Stockage (administrateurs) : disque ou bucket S3. Passer de l'un à l'autre se fait par une
 * migration (tâche « storage ») qui copie et vérifie chaque fichier avant de basculer.
 */
#[Route('/api/storage')]
#[IsGranted('ROLE_ADMIN')]
final class StorageController
{
    public function __construct(
        private readonly StorageSettings $settings,
        private readonly BlobStore $store,
        private readonly JobRepository $jobs,
        private readonly JobLauncher $launcher,
        private readonly LockFactory $locks,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $active = $this->active();
        $last = $this->jobs->findOneBy(['type' => 'storage', 'status' => [Job::SUCCEEDED, Job::FAILED]], ['id' => 'DESC']);

        return new JsonResponse([
            'active' => $this->settings->active(),
            'location' => $this->store->backend()->describe(),
            'local' => $this->store->localBackend()->describe(),
            's3' => $this->settings->s3(),
            'job' => $active ? Presenter::job($active) : null,
            'last' => $last ? Presenter::job($last) : null,
        ]);
    }

    /** Paramètres S3 ; point d'accès, bucket et préfixe figés tant que S3 est actif (les fichiers y sont). */
    #[Route('/s3', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $input = StorageSettings::validate($request->toArray());
        if (\is_string($input)) {
            return self::error($input);
        }
        [$config, $secret] = $input;
        $current = $this->settings->s3();
        if ('s3' === $this->settings->active() && null !== $current
            && [$current['endpoint'], $current['bucket'], $current['prefix']] !== [$config['endpoint'], $config['bucket'], $config['prefix']]) {
            return self::error('Stockage S3 actif : le point d\'accès, le bucket et le préfixe ne peuvent pas changer. Revenez d\'abord au disque.');
        }
        if (null === $secret && !$this->settings->hasSecret()) {
            return self::error('Saisissez la clé secrète.');
        }
        $this->settings->saveS3($config, $secret);

        return new JsonResponse(['s3' => $this->settings->s3()]);
    }

    /** Écrit, relit et supprime un petit fichier dans le bucket, avec les paramètres saisis. */
    #[Route('/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        $input = StorageSettings::validate($request->toArray());
        if (\is_string($input)) {
            return new JsonResponse(['ok' => false, 'message' => $input]);
        }
        try {
            return new JsonResponse(['ok' => true, 'message' => $this->settings->s3Backend($input[0], $input[1])->probe()]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'message' => 'Accès au bucket impossible : '.$e->getMessage()]);
        }
    }

    /** Migration vers S3 (ou retour au disque) : une seule à la fois. */
    #[Route('/migrate', methods: ['POST'])]
    public function migrate(): JsonResponse
    {
        if (null === $this->settings->s3()) {
            return self::error('Enregistrez d\'abord les paramètres S3.');
        }
        $lock = $this->locks->createLock('job:storage');
        $lock->acquire(true);
        try {
            $job = $this->active() ?? $this->launcher->launch('storage', 's3' === $this->settings->active() ? 'Retour du stockage sur disque' : 'Migration du stockage vers S3');
        } finally {
            $lock->release();
        }

        return new JsonResponse(Presenter::job($job), Response::HTTP_ACCEPTED);
    }

    private function active(): ?Job
    {
        return $this->jobs->findOneBy(['type' => 'storage', 'status' => [Job::QUEUED, Job::RUNNING]]);
    }

    private static function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
