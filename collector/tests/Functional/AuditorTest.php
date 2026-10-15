<?php

namespace App\Tests\Functional;

use App\Audit\Auditor;
use App\Audit\OsvClient;
use App\Entity\Artifact;
use App\Repository\AdvisoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sas\Bundle\Ecosystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Analyse OSV : gravité, doublons entre bases (GHSA / PYSEC), versions corrigées, paquets non concernés. */
final class AuditorTest extends KernelTestCase
{
    public function testAuditMarksVulnerableArtifacts(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $lodash = new Artifact(Ecosystem::Npm, 'lodash', '4.17.15', null);
        $django = new Artifact(Ecosystem::Pypi, 'django', '4.2', 'Django-4.2-py3-none-any.whl');
        $djangoSdist = new Artifact(Ecosystem::Pypi, 'django', '4.2', 'Django-4.2.tar.gz');
        $safe = new Artifact(Ecosystem::Composer, 'psr/log', 'v3.0.2', null);
        $image = new Artifact(Ecosystem::Docker, 'library/nginx', '1.27', null);
        $recipe = new Artifact(Ecosystem::Composer, '_flex/symfony/console', '5.3', 'recipes');
        foreach ([$lodash, $django, $djangoSdist, $safe, $image, $recipe] as $artifact) {
            $em->persist($artifact);
        }
        $em->flush();

        $queries = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queries) {
            if (str_ends_with($url, '/v1/querybatch')) {
                $queries = json_decode($options['body'], true)['queries'];

                return new MockResponse(json_encode(['results' => [
                    ['vulns' => [['id' => 'GHSA-lodash', 'modified' => '1']]],
                    ['vulns' => [['id' => 'GHSA-django', 'modified' => '1'], ['id' => 'PYSEC-2023-1', 'modified' => '1']]],
                    [],
                ]]));
            }
            $id = basename(parse_url($url, \PHP_URL_PATH));

            return new MockResponse(json_encode(match ($id) {
                'GHSA-lodash' => ['id' => $id, 'summary' => 'Command Injection in lodash', 'database_specific' => ['severity' => 'HIGH'],
                    'aliases' => ['CVE-2021-23337'], 'affected' => [['package' => ['ecosystem' => 'npm', 'name' => 'lodash'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '4.17.21']]]]]]],
                'GHSA-django' => ['id' => $id, 'summary' => 'SQL injection', 'database_specific' => ['severity' => 'CRITICAL'], 'aliases' => ['CVE-2023-9', 'PYSEC-2023-1'],
                    'affected' => [['package' => ['ecosystem' => 'PyPI', 'name' => 'Django'], 'ranges' => [['events' => [['fixed' => '4.2.26']]]]]]],
                // Même faille vue par PyPA : écartée au profit de la fiche GitHub, plus précise
                'PYSEC-2023-1' => ['id' => $id, 'details' => "SQL injection\nDétails", 'aliases' => ['GHSA-django']],
            }));
        });

        $auditor = new Auditor(new OsvClient($http, static::getContainer()->get(AdvisoryRepository::class), $em), $em);
        $result = $auditor->audit([$lodash, $django, $djangoSdist, $safe, $image, $recipe]);

        // Docker et recettes Flex non interrogés ; les deux fichiers Django ne font qu'une requête ; « v » retiré pour Packagist
        self::assertSame([
            ['package' => ['name' => 'lodash', 'ecosystem' => 'npm'], 'version' => '4.17.15'],
            ['package' => ['name' => 'django', 'ecosystem' => 'PyPI'], 'version' => '4.2'],
            ['package' => ['name' => 'psr/log', 'ecosystem' => 'Packagist'], 'version' => '3.0.2'],
        ], $queries);
        self::assertSame(4, $result['audited']);
        self::assertSame(['high' => 1, 'critical' => 2], $result['counts']);

        self::assertSame('high', $lodash->getSeverity());
        self::assertSame(['4.17.21'], $lodash->getVulnerabilities()[0]['fixed']);
        self::assertSame(['CVE-2021-23337'], $lodash->getVulnerabilities()[0]['aliases']);
        self::assertSame('critical', $django->getSeverity());
        self::assertCount(1, $django->getVulnerabilities());
        self::assertSame('GHSA-django', $djangoSdist->getVulnerabilities()[0]['id']);
        self::assertSame(['4.2.26'], $django->getVulnerabilities()[0]['fixed']);
        self::assertNull($safe->getSeverity());
        self::assertNotNull($safe->getAuditedAt());
        self::assertNull($image->getAuditedAt());

        // Les alertes partent dans le bundle avec le paquet
        self::assertSame('high', $lodash->toManifest()['metadata']['security']['severity']);
    }
}
