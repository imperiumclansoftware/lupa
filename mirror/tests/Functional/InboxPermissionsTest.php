<?php

namespace App\Tests\Functional;

use App\Tests\MirrorWebTestCase;
use App\Tests\Support\BundleBuilder;
use Sas\Bundle\Signer;

/** Un dossier de clé USB illisible est signalé, sans empêcher de lister les autres bundles. */
final class InboxPermissionsTest extends MirrorWebTestCase
{
    public function testUnreadableFolderIsReportedNotFatal(): void
    {
        $builder = new BundleBuilder($this->dir.'/source');
        $builder->artifact('npm', 'demo', '1.0.0', null, [$builder->blob('x')], ['dist' => ['tarballName' => 'demo-1.0.0.tgz']]);
        mkdir($this->dir.'/inbox/cle', 0o755);
        $builder->write($this->dir.'/inbox/cle', Signer::generateKeyPair());

        mkdir($this->dir.'/inbox/autre-utilisateur', 0o000);
        if (is_readable($this->dir.'/inbox/autre-utilisateur')) {
            chmod($this->dir.'/inbox/autre-utilisateur', 0o755);
            self::markTestSkipped('Les droits ne sont pas appliqués dans cet environnement (root avec privilèges).');
        }

        $this->login();
        $this->client->request('GET', '/api/inbox');
        self::assertResponseIsSuccessful();
        $data = $this->json();
        chmod($this->dir.'/inbox/autre-utilisateur', 0o755);

        self::assertCount(1, $data['items']);
        self::assertSame([$this->dir.'/inbox/autre-utilisateur'], array_column($data['unreadable'], 'path'));
    }
}
