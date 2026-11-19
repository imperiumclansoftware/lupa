<?php

namespace App\Tests\Functional;

use App\Entity\Job;
use App\Entity\Package;
use App\Tests\MirrorWebTestCase;
use Sas\Bundle\BundleReader;

/**
 * Contrat du bundle, côté Romulus : rejoue les bundles produits par le vrai code d'export de Rémus
 * (fixture tests/fixtures/contract, générée par collector/tests/Functional/BundleContractTest) à travers
 * BundleInspector, ImportRunner et Quarantine::apply, sans passer par BundleBuilder.
 *
 *  1. lodash 4.17.20 (alertes OSV) et left-pad 1.3.0 ; liste noire révision 1 : evil 1.0.0
 *  2. incrémental sans artefact ; révision 2 : + lodash 4.17.20, déjà exporté
 *  3. incrémental sans artefact ; révision 3 : lodash retiré de la liste noire
 */
final class BundleContractTest extends MirrorWebTestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/contract';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1, 2, 3] as $n) {
            copy(\sprintf('%s/bundle-%d.tar', self::FIXTURES, $n), \sprintf('%s/inbox/contrat-%d.tar', $this->dir, $n));
        }
        $this->login();
    }

    public function testUntrustedCollectorIsRefused(): void
    {
        $this->client->request('GET', '/api/inbox');
        $report = $this->report('contrat-1');
        self::assertFalse($report['collector']['trusted']);
        self::assertFalse($report['signatureValid']);
        self::assertSame(2, $report['artifactCount']);
        self::assertSame(['revision' => 1, 'entries' => 1], $report['blacklist']);

        $job = $this->import('contrat-1');
        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('non approuvé', (string) $job->getError());
        self::assertSame(0, $this->countRows(Package::class));
    }

    public function testBundleAlteredAfterSigningIsRefused(): void
    {
        $this->trustFixtureCollector();
        // Un octet du manifeste change (même longueur : l'archive reste lisible), la signature non
        $file = $this->dir.'/inbox/contrat-1.tar';
        $tar = (string) file_get_contents($file);
        $altered = preg_replace('/"version":"4\.17\.20"/', '"version":"4.17.99"', $tar, 1, $count);
        self::assertSame(1, $count);
        file_put_contents($file, $altered);

        $this->client->request('GET', '/api/inbox');
        self::assertFalse($this->report('contrat-1')['signatureValid']);
        $job = $this->import('contrat-1');
        self::assertSame(Job::FAILED, $job->getStatus());
        self::assertStringContainsString('Signature invalide', (string) $job->getError());
        self::assertSame(0, $this->countRows(Package::class));
    }

    public function testArtifactsAndSecurityAlertsArrive(): void
    {
        $this->trustFixtureCollector();
        $this->client->request('GET', '/api/inbox');
        $report = $this->report('contrat-1');
        self::assertTrue($report['collector']['trusted']);
        self::assertTrue($report['signatureValid']);

        $this->assertImported('contrat-1');
        self::assertSame(2, $this->countRows(Package::class));

        $this->client->request('GET', '/api/packages/npm/lodash');
        $version = $this->json()['items'][0];
        self::assertSame('4.17.20', $version['version']);
        self::assertSame('high', $version['severity']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $version['auditedAt']));
        self::assertSame('GHSA-35jh-r3h4-6jhm', $version['vulnerabilities'][0]['id']);
        self::assertSame(['4.17.21'], $version['vulnerabilities'][0]['fixed']);
        self::assertNull($version['quarantine'], 'evil 1.0.0 seulement en liste noire');

        $this->client->request('GET', '/api/packages/npm/left-pad');
        self::assertNull($this->json()['items'][0]['severity'], 'jamais analysé : aucune alerte');

        // Liste noire révision 1 appliquée : evil 1.0.0, absent du miroir
        $this->client->request('GET', '/api/packages/-/quarantine');
        self::assertSame(['evil'], array_column($this->json()['items'], 'name'));
    }

    public function testOlderBlacklistRevisionIsIgnored(): void
    {
        $this->trustFixtureCollector();
        $this->assertImported('contrat-1');
        // Bundle 3 (révision 3, lodash autorisé) avant le bundle 2 (révision 2, lodash interdit)
        $this->assertImported('contrat-3');
        $job = $this->assertImported('contrat-2');
        self::assertStringContainsString('déjà appliquée', $job->getLog());

        self::assertNull($this->lodash()['quarantine'], 'la révision 2 est plus ancienne que la 3 déjà appliquée');
        self::assertContains('4.17.20', $this->servedNpmVersions('lodash'));
    }

    /** Tâche 3 : liste noire de bout en bout, par des bundles incrémentaux sans artefact. */
    public function testBlacklistAfterExportQuarantinesThenReleases(): void
    {
        $this->trustFixtureCollector();
        $this->assertImported('contrat-1');
        self::assertContains('4.17.20', $this->servedNpmVersions('lodash'));

        // Interdite après son export : quarantaine au bundle suivant, qui ne transporte aucun paquet
        $this->assertImported('contrat-2');
        self::assertSame('CVE-2021-23337', $this->lodash()['quarantine']['reason'] ?? null);
        self::assertNotContains('4.17.20', $this->servedNpmVersions('lodash'));
        $this->client->request('GET', '/api/packages/-/quarantine');
        self::assertEqualsCanonicalizing(['evil', 'lodash'], array_column($this->json()['items'], 'name'));

        // Retirée de la liste noire : de nouveau servie
        $this->assertImported('contrat-3');
        self::assertNull($this->lodash()['quarantine']);
        self::assertContains('4.17.20', $this->servedNpmVersions('lodash'));
    }

    private function trustFixtureCollector(): void
    {
        $this->trust((new BundleReader([self::FIXTURES.'/bundle-1.tar']))->manifest()->collectorPublicKey);
    }

    private function assertImported(string $name): Job
    {
        $job = $this->import($name);
        self::assertSame(Job::SUCCEEDED, $job->getStatus(), (string) $job->getError());

        return $job;
    }

    /** @return array<string, mixed> */
    private function report(string $name): array
    {
        foreach ($this->json()['items'] as $item) {
            if ($name === $item['name']) {
                self::assertNull($item['error']);

                return $item['report'];
            }
        }
        self::fail(\sprintf('Bundle %s absent de la boîte de réception.', $name));
    }

    /** @return array<string, mixed> */
    private function lodash(): array
    {
        $this->client->request('GET', '/api/packages/npm/lodash');
        self::assertResponseIsSuccessful();

        return $this->json()['items'][0];
    }

    /** @return list<string> versions annoncées par le registre npm du miroir */
    private function servedNpmVersions(string $name): array
    {
        $this->client->request('GET', '/npm/'.$name);
        $status = $this->client->getInternalResponse()->getStatusCode();
        // Toutes les versions en quarantaine : le registre refuse le paquet en donnant la raison
        if (403 === $status) {
            self::assertStringContainsString('quarantaine', (string) $this->json()['error']);

            return [];
        }
        self::assertResponseIsSuccessful();

        return array_map(strval(...), array_keys($this->json()['versions'] ?? []));
    }
}
