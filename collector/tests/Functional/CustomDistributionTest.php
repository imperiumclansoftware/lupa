<?php

namespace App\Tests\Functional;

use App\Blacklist\Blacklist;
use App\Entity\CustomDistribution;
use App\Entity\PackageList;
use App\Entity\User;
use App\Http\Downloader;
use App\Job\JobContext;
use App\Resolver\ArtifactCatalog;
use App\Resolver\DebResolver;
use App\Source\Sources;
use App\System\CustomDistributions;
use App\System\DebIndex;
use App\System\IndexFiles;
use App\System\Signatures;
use App\Tests\Support\Csrf;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\NilUuid;

/**
 * Distributions personnalisées : saisie (Réglages), ajout au catalogue, résolution contre un dépôt simulé
 * signé par leur propre clé, description transmise à Romulus avec chaque paquet.
 */
final class CustomDistributionTest extends WebTestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/apt';
    private const string REPO = 'https://apt.interne.test/debian';

    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sas-custom-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        $_ENV['STORAGE_DIR'] = $_SERVER['STORAGE_DIR'] = $this->dir.'/storage';
        putenv('STORAGE_DIR='.$this->dir.'/storage');
        $this->client = static::createClient();
        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $user = new User('admin');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret-test')); // gitleaks:allow
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

    public function testLifecycle(): void
    {
        $input = $this->input();
        foreach ([
            [['slug' => 'debian-12'] + $input, 'déjà utilisé'],
            [['slug' => 'Interne'] + $input, 'Identifiant invalide'],
            [['codename' => 'Bookworm Interne'] + $input, 'Nom de code invalide'],
            [['architectures' => ''] + $input, 'Architectures invalides'],
            [['sources' => 'pas-une-adresse bookworm'] + $input, 'Ligne 1 invalide'],
            [['signingKey' => 'pas une clé'] + $input, 'Clé de signature invalide'],
        ] as [$body, $error]) {
            $this->client->jsonRequest('POST', '/api/custom-distributions', $body);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString($error, (string) $this->json()['error']);
        }

        $this->client->jsonRequest('POST', '/api/custom-distributions', $input);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json();
        self::assertSame(['debian-12-interne', 'F9BD3017B38B644C8F9EFF2D29A000A37390BBF1', self::REPO.' bookworm'], [$created['slug'], $created['keyFingerprint'], $created['sources']]);
        $this->client->jsonRequest('POST', '/api/custom-distributions', $input);
        self::assertResponseStatusCodeSame(422);

        // Proposée dans les formulaires de liste comme une distribution intégrée
        $this->client->request('GET', '/api/ecosystems');
        $distributions = array_column($this->json()['distributions'], null, 'id');
        self::assertSame('Debian 12 interne', $distributions['debian-12-interne']['label'] ?? null);
        self::assertSame(['main'], array_column($distributions['debian-12-interne']['sections'], 'id'));

        // Modification sans recoller la clé ; identifiant figé
        $this->client->jsonRequest('PUT', '/api/custom-distributions/'.$created['id'], ['signingKey' => null, 'eol' => true] + $input);
        self::assertResponseIsSuccessful();
        self::assertSame([true, 'F9BD3017B38B644C8F9EFF2D29A000A37390BBF1'], [$this->json()['eol'], $this->json()['keyFingerprint']]);
        $this->client->jsonRequest('PUT', '/api/custom-distributions/'.$created['id'], ['slug' => 'autre-nom'] + $input);
        self::assertResponseStatusCodeSame(422);

        // Suppression refusée tant qu'une liste l'utilise
        $list = new PackageList('Interne', Ecosystem::Deb);
        $list->setOptions(['distribution' => 'debian-12-interne']);
        $this->em()->persist($list);
        $this->em()->flush();
        $this->client->request('DELETE', '/api/custom-distributions/'.$created['id']);
        self::assertResponseStatusCodeSame(409);
        $this->em()->remove($this->em()->find(PackageList::class, $list->getId()) ?? self::fail('liste'));
        $this->em()->flush();
        $this->client->request('DELETE', '/api/custom-distributions/'.$created['id']);
        self::assertResponseStatusCodeSame(204);
        self::assertNull(Distributions::find('debian-12-interne'));
    }

    public function testResolutionWithItsOwnKey(): void
    {
        $distribution = new CustomDistribution('debian-12-interne', Ecosystem::Deb);
        $distribution->update('Debian 12 interne', '12', 'bookworm-interne', false, ['amd64'], ['main'], [['url' => self::REPO, 'suite' => 'bookworm']], self::fixture('key.asc'));
        $this->em()->persist($distribution);
        $this->em()->flush();
        static::getContainer()->get(CustomDistributions::class)->reset();

        $files = [
            self::REPO.'/dists/bookworm/InRelease' => self::fixture('official-InRelease'),
            self::REPO.'/dists/bookworm/main/binary-amd64/Packages' => self::fixture('official-Packages'),
            self::REPO.'/pool/main/b/base-files/base-files_12.4_amd64.deb' => self::fixture('base-files.deb'),
        ];
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => isset($files[$url]) ? new MockResponse($files[$url]) : new MockResponse('', ['http_code' => 404]));
        $c = static::getContainer();
        $sources = $c->get(Sources::class);
        // Aucune clé livrée : seule la clé de la distribution permet de vérifier l'index
        $resolver = new DebResolver(new DebIndex(new IndexFiles($http, $this->dir.'/cache', $sources), new Signatures($this->dir.'/no-keys'), $sources), new Downloader($http, $c->get(BlobStore::class), $sources), $c->get(ArtifactCatalog::class), $c->get(Blacklist::class));

        $list = new PackageList('Interne', Ecosystem::Deb);
        $list->setSpecs('base-files');
        $list->setOptions(['distribution' => 'debian-12-interne', 'architectures' => ['amd64'], 'sections' => ['main']]);
        $this->em()->persist($list);

        [$artifact] = $resolver->resolve($list, new JobContext($this->em()->getConnection(), new NilUuid()));

        self::assertSame('debian-12-interne/pool/main/b/base-files/base-files_12.4_amd64.deb', $artifact->getFilename());
        self::assertSame(
            ['ecosystem' => 'deb', 'label' => 'Debian 12 interne', 'codename' => 'bookworm-interne', 'release' => '12', 'eol' => false, 'architectures' => ['amd64'], 'sections' => ['main']],
            $artifact->getMetadata()['distributionInfo'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'ecosystem' => 'deb', 'slug' => 'debian-12-interne', 'label' => 'Debian 12 interne', 'release' => '12',
            'codename' => 'bookworm-interne', 'architectures' => 'amd64', 'sections' => 'main',
            'sources' => self::REPO.' bookworm', 'signingKey' => self::fixture('key.asc'),
        ];
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

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name);
    }
}
