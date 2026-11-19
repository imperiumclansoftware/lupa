<?php

namespace App\Tests\Functional;

use App\Blacklist\Blacklist;
use App\Controller\ExportController;
use App\Entity\Artifact;
use App\Entity\Blob;
use App\Entity\Export;
use App\Entity\Job;
use App\Entity\User;
use App\Identity\CollectorIdentity;
use App\Resolver\ArtifactCatalog;
use App\Tests\Support\Csrf;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleReader;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\Manifest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Export vers la « clé USB » sans réseau : les artefacts sont enregistrés directement dans le catalogue,
 * comme après une résolution, puis exportés par l'API (transport synchrone en test).
 */
final class ExportTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-collector-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0o777, true);
        self::setEnv('STORAGE_DIR', $this->dir.'/storage');
        // Dossier d'export créé par l'export lui-même
        self::setEnv('EXPORT_DIR', $this->dir.'/usb');

        $this->client = static::createClient();
        $em = $this->em();
        // Clés étrangères suspendues : SQLite refuse sinon de supprimer une table encore référencée
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $user = new User('admin');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test'));
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
        Csrf::prime($this->client);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testFullThenIncrementalExports(): void
    {
        $a = $this->blob('archive npm a');
        $b = $this->blob('roue python b');
        $this->record(Ecosystem::Npm, 'a', '1.0.0', null, [$a]);
        $this->record(Ecosystem::Pypi, 'b', '2.0.0', 'b-2.0.0-py3-none-any.whl', [$b]);

        $this->client->request('GET', '/api/exports');
        self::assertResponseIsSuccessful();
        self::assertSame(['artifacts' => 2, 'blobs' => 2, 'size' => \strlen('archive npm a') + \strlen('roue python b')], $this->json()['pending']);

        // Export n° 1 : tout ce qui n'a jamais été transporté
        $manifest = $this->export(false, 1);
        self::assertFalse($manifest->full);
        self::assertEqualsCanonicalizing(['a', 'b'], array_column($manifest->artifacts, 'name'));
        self::assertEqualsCanonicalizing([$a['sha256'], $b['sha256']], array_column($manifest->blobs, 'sha256'));
        self::assertSame(0, $this->em()->getRepository(Artifact::class)->count(['export' => null]));
        self::assertSame(0, $this->em()->getRepository(Blob::class)->count(['export' => null]));

        // Rien de nouveau : refus immédiat, sans tâche
        $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Rien de nouveau à exporter.', $this->json()['error']);
        self::assertSame(1, $this->em()->getRepository(Export::class)->count([]));

        // Nouvel artefact qui réutilise le fichier de « a » et en apporte un nouveau
        $c = $this->blob('fichier nouveau de c');
        $this->record(Ecosystem::Npm, 'c', '1.0.0', null, [$a, $c]);

        $manifest = $this->export(false, 2);
        self::assertSame(['c'], array_column($manifest->artifacts, 'name'));
        self::assertSame([$a['sha256'], $c['sha256']], $manifest->artifacts[0]['blobs']);
        // Seul le nouveau fichier est transporté : « a » est déjà sur le miroir
        self::assertSame([['sha256' => $c['sha256'], 'size' => $c['size']]], $manifest->blobs);

        // Export complet (nouveau miroir) : tout le contenu
        $manifest = $this->export(true, 3);
        self::assertTrue($manifest->full);
        self::assertEqualsCanonicalizing(['a', 'b', 'c'], array_column($manifest->artifacts, 'name'));
        self::assertEqualsCanonicalizing([$a['sha256'], $b['sha256'], $c['sha256']], array_column($manifest->blobs, 'sha256'));

        // L'historique conserve le premier bundle qui a transporté chaque artefact
        $first = $this->em()->getRepository(Artifact::class)->findOneBy(['name' => 'a'])->getExport();
        self::assertSame(1, $first->getSequence());
    }

    public function testEmptyCollectorCannotBeExported(): void
    {
        $this->client->jsonRequest('POST', '/api/exports', ['full' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Le collecteur est vide.', $this->json()['error']);
    }

    public function testExportRequiresLogin(): void
    {
        $this->client->request('POST', '/api/logout');
        $this->client->jsonRequest('POST', '/api/exports', ['full' => true]);
        self::assertResponseStatusCodeSame(401);
    }

    /** Deux demandes simultanées : la seconde est refusée au lieu de réserver le même numéro (symfony/lock). */
    public function testConcurrentExportRequestIsRefused(): void
    {
        $this->record(Ecosystem::Npm, 'a', '1.0.0', null, [$this->blob('archive npm a')]);
        $lock = static::getContainer()->get(LockFactory::class)->createLock(ExportController::LOCK);
        self::assertTrue($lock->acquire());
        try {
            $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame(0, $this->em()->getRepository(Export::class)->count([]));
        } finally {
            $lock->release();
        }
        $this->export(false, 1);
    }

    public function testMissingBlobFailsTheJobAndFreesTheSequence(): void
    {
        $a = $this->blob('sera supprimé');
        $this->record(Ecosystem::Npm, 'a', '1.0.0', null, [$a]);
        static::getContainer()->get(BlobStore::class)->delete($a['sha256']);

        $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
        self::assertResponseStatusCodeSame(202);
        $job = $this->em()->find(Job::class, $this->json()['id']);
        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('absent du stockage', (string) $job->getError());
        // Numéro libéré : le prochain export reprendra le n° 1
        self::assertSame(0, $this->em()->getRepository(Export::class)->count([]));
        self::assertSame([], glob($this->dir.'/usb/*') ?: []);
    }

    /**
     * Échec après l'écriture du bundle (enregistrement final en base) : rien ne doit rester, ni en base
     * (artefacts marqués exportés, liste noire marquée transmise, numéro consommé) ni sur le support.
     */
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        // Erreur SQL pendant l'enregistrement : Doctrine annule la transaction et ferme l'EntityManager
        yield 'EntityManager fermé' => [Events::postUpdate];
        // Erreur avant la transaction : l'EntityManager reste ouvert avec les changements en mémoire
        yield 'EntityManager ouvert' => [Events::onFlush];
    }

    #[DataProvider('failures')]
    public function testFailureAfterWritingLeavesNothingBehind(string $event): void
    {
        $a = $this->blob('archive npm a');
        $this->record(Ecosystem::Npm, 'a', '1.0.0', null, [$a]);
        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.20', 'reason' => 'test']);
        self::assertResponseStatusCodeSame(201);

        // Même noyau pour la requête suivante : l'écouteur doit voir l'enregistrement final de l'export
        $this->client->disableReboot();
        $failure = new class {
            public bool $armed = true;

            public function postUpdate(PostUpdateEventArgs $args): void
            {
                if ($args->getObject() instanceof Export && [] !== $args->getObject()->getFiles()) {
                    $this->fail();
                }
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Export && [] !== $entity->getFiles()) {
                        $this->fail();
                    }
                }
            }

            private function fail(): void
            {
                if ($this->armed) {
                    $this->armed = false;
                    throw new \RuntimeException('Base indisponible (simulé)');
                }
            }
        };
        $this->em()->getEventManager()->addEventListener($event, $failure);

        $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
        self::assertResponseStatusCodeSame(202);
        self::assertFalse($failure->armed, 'l\'échec simulé doit s\'être produit');

        $em = $this->resetEm();
        $job = $em->find(Job::class, $this->json()['id']);
        self::assertNotNull($job);
        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('Base indisponible', (string) $job->getError());
        self::assertSame(0, $em->getRepository(Export::class)->count([]), 'numéro d\'export libéré');
        self::assertSame(1, $em->getRepository(Artifact::class)->count(['export' => null]), 'artefact toujours à exporter');
        self::assertSame(1, $em->getRepository(Blob::class)->count(['export' => null]), 'fichier toujours à exporter');
        self::assertTrue(static::getContainer()->get(Blacklist::class)->pendingExport(), 'liste noire toujours à transmettre');
        self::assertSame([], glob($this->dir.'/usb/sas-*') ?: [], 'aucun fichier de bundle laissé sur le support');

        // L'export suivant reprend le n° 1, avec la liste noire
        $this->client->enableReboot();
        $manifest = $this->export(false, 1);
        self::assertSame(['a'], array_column($manifest->artifacts, 'name'));
        self::assertNotNull($manifest->blacklist);
    }

    /** EntityManager neuf (l'ancien peut avoir été fermé par l'échec) */
    private function resetEm(): EntityManagerInterface
    {
        $doctrine = static::getContainer()->get('doctrine');
        $doctrine->resetManager();
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** Lance un export, vérifie la tâche et relit le bundle écrit (signature comprise). */
    private function export(bool $full, int $sequence): Manifest
    {
        $this->client->jsonRequest('POST', '/api/exports', ['full' => $full]);
        self::assertResponseStatusCodeSame(202);
        $em = $this->em();
        $em->clear();
        $job = $em->find(Job::class, $this->json()['id']);
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());

        $identity = static::getContainer()->get(CollectorIdentity::class);
        $base = BundleFiles::baseName($identity->name(), $sequence);
        $file = $this->dir.'/usb/'.$base.'.tar';
        self::assertFileExists($file);

        $export = $em->getRepository(Export::class)->findOneBy(['sequence' => $sequence]);
        self::assertSame([$base.'.tar'], $export->getFiles());

        $reader = new BundleReader([$file]);
        self::assertTrue($reader->verifySignature($identity->publicKey()));
        $manifest = $reader->manifest();
        self::assertSame($sequence, $manifest->sequence);
        self::assertSame($export->getUuid(), $manifest->id);

        // Les fichiers transportés sont bien ceux annoncés, empreintes vérifiées
        $target = new BlobStore($this->dir.'/check-'.$sequence);
        self::assertEqualsCanonicalizing(array_column($manifest->blobs, 'sha256'), $reader->extractBlobs($target));

        // Téléchargement par le navigateur (Exports › Historique) : fichier identique
        $this->client->request('GET', sprintf('/api/exports/%s/files/%s.tar', $export->getId(), $base));
        $downloaded = $this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/x-tar');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(file_get_contents($file), $downloaded);

        return $manifest;
    }

    /** @return array{sha256: string, size: int} */
    private function blob(string $data): array
    {
        return static::getContainer()->get(BlobStore::class)->putString($data);
    }

    /** @param list<array{sha256: string, size: int}> $blobs */
    private function record(Ecosystem $ecosystem, string $name, string $version, ?string $filename, array $blobs): void
    {
        $catalog = static::getContainer()->get(ArtifactCatalog::class);
        $catalog->record($ecosystem, $name, $version, $filename, $blobs, ['name' => $name]);
        $catalog->flush();
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
