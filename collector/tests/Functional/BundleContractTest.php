<?php

namespace App\Tests\Functional;

use App\Entity\Artifact;
use App\Entity\Job;
use App\Entity\User;
use App\Identity\CollectorIdentity;
use App\Resolver\ArtifactCatalog;
use App\Tests\Support\Csrf;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleFiles;
use Sas\Bundle\BundleReader;
use Sas\Bundle\Ecosystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Contrat du bundle, côté Rémus : produit par le vrai code d'export (Artifact::toManifest, ExportRunner,
 * signature) les bundles que Romulus rejoue dans mirror/tests/Functional/BundleContractTest.
 *
 * Sans variable d'environnement, vérifie que le format produit aujourd'hui a la même structure que la
 * fixture enregistrée (un changement de format doit donc s'accompagner d'une fixture régénérée, et du test
 * de Romulus qui la relit). Pour régénérer : CONTRACT_FIXTURES=write php bin/phpunit --filter BundleContract
 *
 *  1. lodash 4.17.20 (alertes OSV) et left-pad 1.3.0 ; liste noire révision 1 : evil 1.0.0 (jamais collecté)
 *  2. incrémental sans artefact ; révision 2 : + lodash 4.17.20, déjà exporté
 *  3. incrémental sans artefact ; révision 3 : lodash retiré de la liste noire
 */
final class BundleContractTest extends WebTestCase
{
    public const string FIXTURES = __DIR__.'/../../../mirror/tests/fixtures/contract';

    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-contract-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0o777, true);
        foreach (['STORAGE_DIR' => $this->dir.'/storage', 'EXPORT_DIR' => $this->dir.'/usb', 'IDENTITY_DIR' => $this->dir.'/identity'] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        $this->client = static::createClient();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $user = new User('admin');
        $user->setPassword('!');
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

    public function testBundlesMatchTheContractFixture(): void
    {
        $catalog = static::getContainer()->get(ArtifactCatalog::class);
        $store = static::getContainer()->get(BlobStore::class);
        $lodash = $store->putString('lodash-4.17.20.tgz (contrat)');
        $leftPad = $store->putString('left-pad-1.3.0.tgz (contrat)');
        $catalog->record(Ecosystem::Npm, 'lodash', '4.17.20', null, [$lodash], ['name' => 'lodash', 'description' => 'Utilitaires']);
        $catalog->record(Ecosystem::Npm, 'left-pad', '1.3.0', null, [$leftPad], ['name' => 'left-pad']);
        $catalog->flush();
        $em = $this->em();
        $em->getRepository(Artifact::class)->findOneBy(['name' => 'lodash'])?->setAudit([[
            'id' => 'GHSA-35jh-r3h4-6jhm', 'aliases' => ['CVE-2021-23337'], 'summary' => 'Command Injection in lodash',
            'severity' => 'high', 'fixed' => ['4.17.21'], 'url' => 'https://osv.dev/vulnerability/GHSA-35jh-r3h4-6jhm',
        ]], 'high');
        $em->flush();

        $this->ban('evil', '1.0.0', 'Paquet malveillant');
        $bundles = [$this->export(1)];
        $lodashEntry = $this->ban('lodash', '4.17.20', 'CVE-2021-23337');
        $bundles[] = $this->export(2);
        $this->client->request('DELETE', '/api/blacklist/'.$lodashEntry);
        self::assertResponseIsSuccessful();
        $bundles[] = $this->export(3);

        $manifests = array_map(static fn (string $file) => (new BundleReader([$file]))->manifest(), $bundles);
        self::assertSame(['left-pad', 'lodash'], array_column($manifests[0]->artifacts, 'name'));
        self::assertSame('high', $manifests[0]->artifacts[1]['metadata']['security']['severity']);
        self::assertSame([], $manifests[1]->artifacts);
        self::assertSame([1, 2, 3], array_map(static fn ($m) => $m->blacklist['revision'] ?? null, $manifests));

        if ('write' === getenv('CONTRACT_FIXTURES')) {
            exec('rm -rf '.escapeshellarg(self::FIXTURES));
            mkdir(self::FIXTURES, 0o777, true);
            foreach ($bundles as $i => $file) {
                copy($file, \sprintf('%s/bundle-%d.tar', self::FIXTURES, $i + 1));
            }
            self::markTestIncomplete('Fixture du contrat régénérée dans mirror/tests/fixtures/contract : relancez les tests de Romulus.');
        }

        // Même structure que la fixture que Romulus rejoue (clés et types, pas les valeurs)
        foreach ($bundles as $i => $file) {
            $fixture = \sprintf('%s/bundle-%d.tar', self::FIXTURES, $i + 1);
            self::assertFileExists($fixture, 'Fixture absente : CONTRACT_FIXTURES=write php bin/phpunit --filter BundleContract');
            self::assertSame(
                self::shape(json_decode((new BundleReader([$fixture]))->manifest()->toJson(), true)),
                self::shape(json_decode($manifests[$i]->toJson(), true)),
                \sprintf('Le format du bundle %d a changé : régénérez la fixture (CONTRACT_FIXTURES=write) et vérifiez que Romulus la relit.', $i + 1),
            );
        }
    }

    /** Structure d'une valeur JSON : clés et types, récursivement (les listes par leur premier élément) */
    private static function shape(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return get_debug_type($value);
        }
        if (array_is_list($value)) {
            return [] === $value ? 'list' : ['list of' => self::shape($value[0])];
        }
        ksort($value);

        return array_map(self::shape(...), $value);
    }

    private function ban(string $name, string $version, string $reason): string
    {
        $this->client->jsonRequest('POST', '/api/blacklist', ['ecosystem' => 'npm', 'name' => $name, 'version' => $version, 'reason' => $reason]);
        self::assertResponseStatusCodeSame(201);

        return (string) json_decode((string) $this->client->getInternalResponse()->getContent(), true)['id'];
    }

    private function export(int $sequence): string
    {
        $this->client->jsonRequest('POST', '/api/exports', ['full' => false]);
        self::assertResponseStatusCodeSame(202);
        $em = $this->em();
        $em->clear();
        $job = $em->find(Job::class, json_decode((string) $this->client->getInternalResponse()->getContent(), true)['id']);
        self::assertSame(Job::SUCCEEDED, $job?->getStatus(), (string) $job?->getError());

        $file = \sprintf('%s/usb/%s.tar', $this->dir, BundleFiles::baseName(static::getContainer()->get(CollectorIdentity::class)->name(), $sequence));
        self::assertFileExists($file);

        return $file;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
