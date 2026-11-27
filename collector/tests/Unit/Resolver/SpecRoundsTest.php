<?php

namespace App\Tests\Unit\Resolver;

use App\Entity\PackageList;
use App\Job\JobContext;
use App\Resolver\SpecRounds;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;
use Symfony\Component\Uid\Uuid;

/** Une version par paquet et par passe : npm, Composer et pip n'en retiennent qu'une par résolution. */
final class SpecRoundsTest extends TestCase
{
    public function testOneVersionPerPackageAndPerRound(): void
    {
        $rounds = SpecRounds::split(
            ['react@16.0.0', 'lodash@^4', 'react@17.0.2', 'react-dom@16.0.0', 'react@18.3.1', 'react-dom@17.0.2'],
            static fn (string $line) => explode('@', $line)[0],
        );

        self::assertSame([
            ['react@16.0.0', 'lodash@^4', 'react-dom@16.0.0'],
            ['react@17.0.2', 'react-dom@17.0.2'],
            ['react@18.3.1'],
        ], $rounds);
    }

    public function testSingleRoundWithoutDuplicates(): void
    {
        self::assertSame([['a@1', 'b@2']], SpecRounds::split(['a@1', 'b@2'], static fn (string $l) => explode('@', $l)[0]));
        self::assertSame([[]], SpecRounds::split([], static fn (string $l) => $l));
    }

    public function testFailingRangeVersionIsSkippedAndOthersKept(): void
    {
        [$list, $context, $log] = $this->list(['react@16.0.0', 'react@17.0.2', 'react@18.3.1'], ['lodash@^4']);
        $calls = [];
        $results = SpecRounds::resolve(
            $list->getSpecLines(),
            static fn (string $l) => explode('@', $l)[0],
            static function (array $lines) use (&$calls): array {
                $calls[] = $lines;
                if (\in_array('react@17.0.2', $lines, true)) {
                    throw new \RuntimeException("La commande « npm install » a échoué :\nnpm error code ETARGET\nnpm error notarget No matching version found for scheduler@^9.");
                }

                return $lines;
            },
            $list,
            $context,
        );

        self::assertSame([['react@16.0.0', 'lodash@^4'], ['react@18.3.1']], $results);
        self::assertSame(['react@17.0.2' => 'npm error notarget No matching version found for scheduler@^9.'], $list->getSkippedVersions());
        self::assertStringContainsString('version ignorée', $log());
    }

    public function testFailingRoundIsolatesTheRangeVersionFromOrdinaryLines(): void
    {
        [$list, $context] = $this->list(['react@16.0.0', 'react@17.0.2'], ['lodash@^4']);
        $results = SpecRounds::resolve(
            $list->getSpecLines(),
            static fn (string $l) => explode('@', $l)[0],
            static function (array $lines): array {
                if (\in_array('react@16.0.0', $lines, true)) {
                    throw new \RuntimeException('ERESOLVE');
                }

                return $lines;
            },
            $list,
            $context,
        );

        // lodash rejoué seul, react@16.0.0 ignoré, react@17.0.2 résolu dans sa passe
        self::assertSame([['lodash@^4'], ['react@17.0.2']], $results);
        self::assertSame(['react@16.0.0'], array_keys($list->getSkippedVersions()));
    }

    public function testFailingOrdinaryLineStillFailsTheList(): void
    {
        [$list, $context] = $this->list(['react@16.0.0'], ['lodash@^99']);
        $this->expectException(\RuntimeException::class);
        SpecRounds::resolve(
            $list->getSpecLines(),
            static fn (string $l) => explode('@', $l)[0],
            static function (array $lines): array {
                if (\in_array('lodash@^99', $lines, true)) {
                    throw new \RuntimeException('No matching version found for lodash@^99');
                }

                return $lines;
            },
            $list,
            $context,
        );
    }

    /**
     * @param list<string> $rangeVersions
     * @param list<string> $ordinary
     *
     * @return array{PackageList, JobContext, \Closure(): string}
     */
    private function list(array $rangeVersions, array $ordinary): array
    {
        $list = new PackageList('essai', Ecosystem::Npm);
        $list->useExpandedSpecs([...$rangeVersions, ...$ordinary], $rangeVersions);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement("CREATE TABLE job (id BLOB, step TEXT, progress INT, report TEXT, log TEXT DEFAULT '')");
        $id = Uuid::v7();
        $db->insert('job', ['id' => $id->toBinary()], [ParameterType::BINARY]);
        $context = new JobContext($db, $id);

        return [$list, $context, static function () use ($context, $db): string {
            $context->report('fin', 100);

            return (string) $db->fetchOne('SELECT log FROM job');
        }];
    }
}
