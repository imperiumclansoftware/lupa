<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** API Galaxy v3 et miroir Terraform (providers, modules, binaire) servis par Romulus. */
final class GalaxyTerraformTest extends MirrorWebTestCase
{
    /** @var array<string, string> */
    private array $sha = [];
    /** @var array{secretKey: string, publicKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = Signer::generateKeyPair();
        $b = new BundleBuilder($this->dir.'/source');
        foreach (['c1', 'c2', 'dep', 'role', 'tf-linux', 'tf-win', 'module', 'cli'] as $role) {
            $this->sha[$role] = $b->blob('contenu '.$role.' '.str_repeat('x', 600));
        }
        foreach (['8.1.0' => 'c1', '9.0.0' => 'c2'] as $version => $role) {
            $b->artifact('galaxy', 'community.general', $version, null, [$this->sha[$role]], [
                'type' => 'collection', 'namespace' => 'community', 'name' => 'general', 'filename' => "community-general-$version.tar.gz",
                'dependencies' => ['community.dep' => '>=1.0.0'],
            ]);
        }
        $b->artifact('galaxy', 'community.dep', '1.2.0', null, [$this->sha['dep']], ['type' => 'collection', 'namespace' => 'community', 'name' => 'dep', 'filename' => 'community-dep-1.2.0.tar.gz', 'dependencies' => []]);
        $b->artifact('galaxy', 'geerlingguy.docker', '7.4.1', 'roles/geerlingguy.docker-7.4.1.tar.gz', [$this->sha['role']], ['type' => 'role', 'namespace' => 'geerlingguy', 'name' => 'docker', 'filename' => 'geerlingguy.docker-7.4.1.tar.gz']);
        foreach (['linux' => 'tf-linux', 'windows' => 'tf-win'] as $os => $role) {
            $b->artifact('terraform', 'registry.terraform.io/hashicorp/random', '3.6.3', "terraform-provider-random_3.6.3_{$os}_amd64.zip", [$this->sha[$role]], [
                'kind' => 'provider', 'host' => 'registry.terraform.io', 'namespace' => 'hashicorp', 'type' => 'random', 'os' => $os, 'arch' => 'amd64',
            ]);
        }
        $b->artifact('terraform', 'terraform-aws-modules/vpc/aws', '5.8.1', 'modules/terraform-aws-modules-vpc-aws-5.8.1.tar.gz', [$this->sha['module']], ['kind' => 'module']);
        $b->artifact('terraform', 'terraform', '1.9.8', 'terraform_1.9.8_linux_amd64.zip', [$this->sha['cli']], ['kind' => 'cli', 'os' => 'linux', 'arch' => 'amd64']);
        $name = $b->write($this->dir.'/inbox', $this->keys);
        $this->login();
        $this->trust($this->keys['publicKey']);
        self::assertSame(Job::SUCCEEDED, $this->import($name)->getStatus());
    }

    public function testGalaxyApi(): void
    {
        $this->client->request('GET', '/galaxy/api/');
        self::assertSame(['v3' => 'v3/'], $this->json()['available_versions']);

        $this->client->request('GET', '/galaxy/api/v3/collections/community/general/');
        self::assertSame('9.0.0', $this->json()['highest_version']['version']);

        $this->client->request('GET', '/galaxy/api/v3/collections/community/general/versions/?limit=100');
        $list = $this->json();
        self::assertSame(2, $list['meta']['count']);
        self::assertNull($list['links']['next']);
        self::assertSame(['9.0.0', '8.1.0'], array_column($list['data'], 'version'));

        $this->client->request('GET', '/galaxy/api/v3/collections/community/general/versions/8.1.0/');
        $detail = $this->json();
        self::assertSame('community', $detail['namespace']['name']);
        self::assertSame($this->sha['c1'], $detail['artifact']['sha256']);
        self::assertSame(['community.dep' => '>=1.0.0'], $detail['metadata']['dependencies']);
        self::assertSame('http://localhost/galaxy/download/community-general-8.1.0.tar.gz', $detail['download_url']);
        $this->client->request('GET', '/galaxy/download/community-general-8.1.0.tar.gz');
        self::assertSame($this->sha['c1'], hash('sha256', $this->body()));

        $this->client->request('GET', '/galaxy/roles/geerlingguy.docker-7.4.1.tar.gz');
        self::assertSame($this->sha['role'], hash('sha256', $this->body()));
        // Un rôle n'est pas une collection
        $this->client->request('GET', '/galaxy/api/v3/collections/geerlingguy/docker/');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTerraformMirror(): void
    {
        $base = '/terraform/providers/registry.terraform.io/hashicorp/random/';
        $this->client->request('GET', $base.'index.json');
        self::assertSame(['versions' => ['3.6.3' => []]], $this->json());
        $this->client->request('GET', $base.'3.6.3.json');
        self::assertSame(['linux_amd64', 'windows_amd64'], array_keys($this->json()['archives']));
        self::assertSame(['url' => 'terraform-provider-random_3.6.3_linux_amd64.zip', 'hashes' => ['zh:'.$this->sha['tf-linux']]], $this->json()['archives']['linux_amd64']);
        $this->client->request('GET', $base.'terraform-provider-random_3.6.3_linux_amd64.zip');
        self::assertSame($this->sha['tf-linux'], hash('sha256', $this->body()));

        // Archive « filesystem mirror » : arborescence hôte/espace/type
        $this->client->request('GET', '/terraform/providers.tar?platform=linux_amd64');
        $response = $this->client->getInternalResponse()->getContent();
        file_put_contents($this->dir.'/p.tar', $response);
        $phar = new \PharData($this->dir.'/p.tar');
        $files = [];
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $files[] = substr($file->getPathname(), \strlen('phar://'.$this->dir.'/p.tar/'));
        }
        self::assertSame(['registry.terraform.io/hashicorp/random/terraform-provider-random_3.6.3_linux_amd64.zip'], $files);

        $this->client->request('GET', '/.well-known/terraform.json');
        self::assertSame(['modules.v1' => '/terraform/modules/v1/'], $this->json());
        $this->client->request('GET', '/terraform/modules/v1/terraform-aws-modules/vpc/aws/versions');
        self::assertSame([['version' => '5.8.1']], $this->json()['modules'][0]['versions']);
        $this->client->request('GET', '/terraform/modules/v1/terraform-aws-modules/vpc/aws/5.8.1/download');
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('X-Terraform-Get', 'http://localhost/terraform/modules/terraform-aws-modules/vpc/aws/5.8.1.tar.gz');
        $this->client->request('GET', '/terraform/modules/terraform-aws-modules/vpc/aws/5.8.1.tar.gz');
        self::assertSame($this->sha['module'], hash('sha256', $this->body()));
        $this->client->request('GET', '/terraform/releases/terraform_1.9.8_linux_amd64.zip');
        self::assertSame($this->sha['cli'], hash('sha256', $this->body()));
    }

    public function testQuarantine(): void
    {
        $b = (new BundleBuilder($this->dir.'/source-2'))->blacklist(1, [
            ['ecosystem' => 'galaxy', 'name' => 'community.general', 'version' => '9.0.0', 'reason' => 'test'],
            ['ecosystem' => 'terraform', 'name' => 'hashicorp/random', 'version' => '3.6.3', 'reason' => 'test'],
        ]);
        self::assertSame(Job::SUCCEEDED, $this->import($b->write($this->dir.'/inbox', $this->keys, 2, false))->getStatus());

        $this->client->request('GET', '/galaxy/api/v3/collections/community/general/versions/');
        self::assertSame(['8.1.0'], array_column($this->json()['data'], 'version'));
        $this->client->request('GET', '/galaxy/api/v3/collections/community/general/versions/9.0.0/');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/terraform/providers/registry.terraform.io/hashicorp/random/index.json');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/terraform/providers/registry.terraform.io/hashicorp/random/terraform-provider-random_3.6.3_linux_amd64.zip');
        self::assertResponseStatusCodeSame(403);
    }
}
