<?php

namespace App\Tests\Functional;

use App\Audit\Auditor;
use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\Job;
use App\Entity\PackageList;
use App\Entity\User;
use App\Integrity\IntegrityChecker;
use App\Integrity\IntegrityRunner;
use App\Job\JobContext;
use App\Job\ResolveRunner;
use App\Repository\ArtifactRepository;
use App\Repository\BlobRepository;
use App\Repository\PackageListRepository;
use App\Resolver\ArtifactCatalog;
use App\Resolver\Resolver;
use App\Search\PatternExpander;
use App\Security\Role;
use App\Tests\Support\Csrf;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérification d'intégrité du stockage : détection (absent, taille, contenu), mise de côté du fichier abîmé,
 * retéléchargement par la mise à jour des listes (résolveur simulé) et rapport.
 */
final class IntegrityTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-integrity-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0o777, true);
        self::setEnv('STORAGE_DIR', $this->dir.'/storage');

        $this->client = static::createClient();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testHealthyStorage(): void
    {
        $this->login(Role::Operator);
        $this->record('a', 'archive npm a');
        $this->record('b', 'archive npm b');

        $this->client->request('GET', '/api/integrity');
        self::assertSame(['job' => null, 'last' => null], $this->json());

        $this->client->jsonRequest('POST', '/api/integrity', []);
        self::assertResponseStatusCodeSame(202);

        $this->client->request('GET', '/api/integrity');
        $last = $this->json()['last'];
        self::assertSame('succeeded', $last['status']);
        self::assertSame(['artifacts' => 2, 'files' => 2, 'damaged' => 0, 'repaired' => 0, 'failed' => 0, 'items' => []], array_intersect_key($last['report'], array_flip(['artifacts', 'files', 'damaged', 'repaired', 'failed', 'items'])));
        self::assertStringContainsString('tous intègres', $last['step']);
    }

    /** Sans liste, Rémus ne sait pas d'où vient le paquet : erreur dans le rapport, fichier abîmé mis de côté */
    public function testDamagedFilesWithoutListAreReportedAsErrors(): void
    {
        $this->login(Role::Operator);
        $hash = $this->record('modifie', 'contenu original');
        $size = $this->record('tronque', 'contenu un peu plus long');
        $missing = $this->record('disparu', 'contenu supprimé');
        $this->record('sain', 'contenu intact');
        $store = static::getContainer()->get(BlobStore::class);
        file_put_contents($store->path($hash), 'contenu ORIGINAL');   // même taille, autre contenu
        file_put_contents($store->path($size), 'contenu');
        unlink($store->path($missing));

        $this->client->jsonRequest('POST', '/api/integrity', []);
        $this->client->request('GET', '/api/integrity');
        $report = $this->json()['last']['report'];

        self::assertSame([3, 0, 3], [$report['damaged'], $report['repaired'], $report['failed']]);
        $problems = [];
        foreach ($report['items'] as $item) {
            self::assertSame('failed', $item['status']);
            self::assertStringContainsString('aucune liste', (string) $item['message']);
            $problems[$item['name']] = $item['files'][0]['problem'];
        }
        self::assertSame(['disparu' => 'missing', 'modifie' => 'hash', 'tronque' => 'size'], $problems);

        // Fichiers abîmés retirés du stockage (l'export ne les transportera pas), conservés dans corrupt/
        self::assertFalse($store->has($hash));
        self::assertFalse($store->has($size));
        self::assertCount(2, glob($this->dir.'/storage/corrupt/*') ?: []);
    }

    public function testReaderCannotLaunchCheck(): void
    {
        $this->login(Role::Reader);
        $this->client->jsonRequest('POST', '/api/integrity', []);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/integrity');
        self::assertResponseIsSuccessful();
    }

    public function testDamagedPackageIsDownloadedAgainThroughItsList(): void
    {
        $sha = $this->record('a', 'contenu original');
        $this->inList('a');
        $store = static::getContainer()->get(BlobStore::class);
        file_put_contents($store->path($sha), 'contenu ABIMÉ!!');

        $report = $this->runCheck($this->resolver(static fn () => 'contenu original'));

        self::assertSame([1, 1, 0], [$report['damaged'], $report['repaired'], $report['failed']]);
        self::assertSame('repaired', $report['items'][0]['status']);
        self::assertSame('Ma liste', $report['items'][0]['lists'][0]['name']);
        self::assertSame($sha, hash_file('sha256', $store->path($sha)));
    }

    public function testPackageStillDamagedWhenTheListCannotBeUpdated(): void
    {
        $sha = $this->record('a', 'contenu original');
        $this->inList('a');
        unlink(static::getContainer()->get(BlobStore::class)->path($sha));

        $report = $this->runCheck($this->resolver(static fn () => throw new \RuntimeException('registre injoignable')));

        self::assertSame([1, 0, 1], [$report['damaged'], $report['repaired'], $report['failed']]);
        self::assertSame('failed', $report['items'][0]['status']);
        self::assertStringContainsString('« Ma liste » en échec : registre injoignable', (string) $report['items'][0]['message']);
    }

    /**
     * Vérification avec un résolveur npm simulé, qui retélécharge le contenu donné.
     *
     * @return array<string, mixed>
     */
    private function runCheck(Resolver $resolver): array
    {
        $c = static::getContainer();
        $em = $this->em();
        $runner = new IntegrityRunner(
            $c->get(IntegrityChecker::class),
            new ResolveRunner([$resolver], $em, $c->get(PatternExpander::class), $c->get(Auditor::class), $c->get(Blacklist::class)),
            $c->get(ArtifactRepository::class),
            $c->get(BlobRepository::class),
            $c->get(PackageListRepository::class),
            $em,
        );
        $job = new Job('integrity', 'Vérification');
        $em->persist($job);
        $em->flush();
        $runner->run($job, new JobContext($em->getConnection(), $job->getId()));

        $report = $em->getConnection()->fetchOne('SELECT report FROM job WHERE id = ?', [$job->getId()->toBinary()], [ParameterType::BINARY]);

        return json_decode((string) $report, true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param \Closure(): string $download contenu « téléchargé » (ou exception) */
    private function resolver(\Closure $download): Resolver
    {
        $catalog = static::getContainer()->get(ArtifactCatalog::class);
        $store = static::getContainer()->get(BlobStore::class);

        return new class($catalog, $store, $download) implements Resolver {
            public function __construct(private ArtifactCatalog $catalog, private BlobStore $store, private \Closure $download)
            {
            }

            public function ecosystem(): Ecosystem
            {
                return Ecosystem::Npm;
            }

            public function validate(PackageList $list): array
            {
                return [];
            }

            public function resolve(PackageList $list, JobContext $context): array
            {
                // Comme les vrais résolveurs : seul un paquet incomplet est retéléchargé
                $artifact = $this->catalog->complete(Ecosystem::Npm, 'a', '1.0.0')
                    ?? $this->catalog->record(Ecosystem::Npm, 'a', '1.0.0', null, [$this->store->putString(($this->download)())], []);
                $this->catalog->flush();

                return [$artifact];
            }
        };
    }

    private function record(string $name, string $data): string
    {
        $blob = static::getContainer()->get(BlobStore::class)->putString($data);
        $catalog = static::getContainer()->get(ArtifactCatalog::class);
        $catalog->record(Ecosystem::Npm, $name, '1.0.0', null, [$blob], []);
        $catalog->flush();

        return $blob['sha256'];
    }

    private function inList(string $name): void
    {
        $em = $this->em();
        $list = new PackageList('Ma liste', Ecosystem::Npm);
        $list->setSpecs($name);
        $list->replaceArtifacts([$em->getRepository(Artifact::class)->findOneBy(['name' => $name]) ?? throw new \LogicException()]);
        $em->persist($list);
        $em->flush();
    }

    private function login(Role $role): void
    {
        $user = new User('compte');
        $user->setRole($role);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test')); // gitleaks:allow
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);
        Csrf::prime($this->client);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array<mixed>
     *
     * @phpstan-impure dernière réponse reçue : change à chaque requête
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getInternalResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
