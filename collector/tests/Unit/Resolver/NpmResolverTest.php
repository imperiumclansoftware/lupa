<?php

namespace App\Tests\Unit\Resolver;

use App\Entity\PackageList;
use App\Resolver\NpmResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;

final class NpmResolverTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function specs(): iterable
    {
        yield 'nom seul' => ['lodash', 'lodash', 'latest'];
        yield 'version' => ['lodash@4.17.21', 'lodash', '4.17.21'];
        yield 'intervalle' => ['lodash@^4.17', 'lodash', '^4.17'];
        yield 'portée sans version' => ['@types/node', '@types/node', 'latest'];
        yield 'portée et version' => ['@types/node@20', '@types/node', '20'];
        yield 'étiquette' => ['react@next', 'react', 'next'];
        yield 'arobase final' => ['lodash@', 'lodash', 'latest'];
        yield 'majuscules' => ['Express@4', 'express', '4'];
    }

    #[DataProvider('specs')]
    public function testParseSpec(string $line, string $name, string $range): void
    {
        self::assertSame([$name, $range], NpmResolver::parseSpec($line));
    }

    public function testLockfileV3(): void
    {
        $entries = $this->lockEntries(self::lockfileV3(), true);

        self::assertSame(
            ['@babel/core@7.24.0', 'bar@2.0.0', 'bundler@1.0.0', 'jest@29.7.0', 'lodash@3.10.1', 'lodash@4.17.21'],
            self::sortedKeys($entries),
        );
        self::assertSame([
            'name' => '@babel/core',
            'version' => '7.24.0',
            'resolved' => 'https://registry.npmjs.org/@babel/core/-/core-7.24.0.tgz',
            'integrity' => 'sha512-babel',
        ], $entries['@babel/core@7.24.0']);
        // Alias « node_modules/foo » -> paquet réel « bar »
        self::assertSame('bar', $entries['bar@2.0.0']['name']);
        self::assertArrayNotHasKey('foo@2.0.0', $entries);
    }

    public function testLockfileV3WithoutDevDependencies(): void
    {
        $entries = $this->lockEntries(self::lockfileV3(), false);

        self::assertSame(['@babel/core@7.24.0', 'bar@2.0.0', 'bundler@1.0.0', 'lodash@3.10.1', 'lodash@4.17.21'], self::sortedKeys($entries));
    }

    public function testLockfileV1WithAlias(): void
    {
        $lock = json_encode([
            'lockfileVersion' => 1,
            'dependencies' => [
                'express' => [
                    'version' => '4.19.2',
                    'resolved' => 'https://registry.npmjs.org/express/-/express-4.19.2.tgz',
                    'dependencies' => [
                        'debug' => ['version' => '2.6.9', 'resolved' => 'https://registry.npmjs.org/debug/-/debug-2.6.9.tgz'],
                    ],
                ],
                'foo' => ['version' => 'npm:bar@1.0.0', 'resolved' => 'https://registry.npmjs.org/bar/-/bar-1.0.0.tgz'],
                'local' => ['version' => 'file:../local'],
                'mocha' => ['version' => '10.0.0', 'resolved' => 'https://registry.npmjs.org/mocha/-/mocha-10.0.0.tgz', 'dev' => true],
            ],
        ]);

        self::assertSame(['bar@1.0.0', 'debug@2.6.9', 'express@4.19.2', 'mocha@10.0.0'], self::sortedKeys($this->lockEntries($lock, true)));
        self::assertSame(['bar@1.0.0', 'debug@2.6.9', 'express@4.19.2'], self::sortedKeys($this->lockEntries($lock, false)));
    }

    public function testValidate(): void
    {
        $resolver = self::resolver();

        $list = new PackageList('Front', Ecosystem::Npm);
        $list->setSpecs("lodash@^4\n@types/node@20 # commentaire\n");
        $list->setLockfile('package-lock.json', self::lockfileV3());
        self::assertSame([], $resolver->validate($list));

        $list->setSpecs("lodash\nPas Valide!\n");
        $list->setLockfile('package-lock.json', '{pas du json');
        $errors = $resolver->validate($list);
        self::assertCount(2, $errors);
        self::assertStringContainsString('« Pas Valide! » : nom de paquet npm invalide', $errors[0]);
        self::assertStringContainsString('JSON illisible', $errors[1]);

        $list->setSpecs('');
        $list->setLockfile('package.json', '{"name": "app"}');
        self::assertStringContainsString('ni « packages » ni « dependencies »', $resolver->validate($list)[0]);

        $list->setLockfile(null, null);
        self::assertStringContainsString('La liste est vide', $resolver->validate($list)[0]);
    }

    private static function lockfileV3(): string
    {
        $registry = 'https://registry.npmjs.org';

        return json_encode([
            'name' => 'app',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'requires' => true,
            'packages' => [
                '' => ['name' => 'app', 'version' => '1.0.0', 'dependencies' => ['lodash' => '^4.17.21']],
                'node_modules/lodash' => ['version' => '4.17.21', 'resolved' => $registry.'/lodash/-/lodash-4.17.21.tgz', 'integrity' => 'sha512-lodash'],
                'node_modules/@babel/core' => ['version' => '7.24.0', 'resolved' => $registry.'/@babel/core/-/core-7.24.0.tgz', 'integrity' => 'sha512-babel'],
                // Dépendance imbriquée : une autre version du même paquet
                'node_modules/old/node_modules/lodash' => ['version' => '3.10.1', 'resolved' => $registry.'/lodash/-/lodash-3.10.1.tgz'],
                'node_modules/jest' => ['version' => '29.7.0', 'resolved' => $registry.'/jest/-/jest-29.7.0.tgz', 'dev' => true],
                // Espace de travail local
                'node_modules/local-lib' => ['resolved' => 'packages/local-lib', 'link' => true],
                'packages/local-lib' => ['name' => 'local-lib', 'version' => '0.1.0'],
                // Paquet embarqué dans « bundler » : rien à télécharger
                'node_modules/bundler' => ['version' => '1.0.0', 'resolved' => $registry.'/bundler/-/bundler-1.0.0.tgz'],
                'node_modules/bundler/node_modules/inner' => ['version' => '1.0.0', 'inBundle' => true],
                // Alias npm:bar@2.0.0 installé sous le nom foo
                'node_modules/foo' => ['name' => 'bar', 'version' => '2.0.0', 'resolved' => $registry.'/bar/-/bar-2.0.0.tgz'],
                // Dépendance git : ignorée
                'node_modules/gitdep' => ['version' => '1.0.0', 'resolved' => 'git+ssh://git@github.com/o/gitdep.git#abc'],
            ],
        ], \JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, array<string, mixed>> */
    private function lockEntries(string $json, bool $includeDev): array
    {
        return (new \ReflectionMethod(NpmResolver::class, 'lockEntries'))->invoke(self::resolver(), $json, $includeDev);
    }

    /** Ni réseau ni base : les méthodes testées n'utilisent pas les dépendances du constructeur. */
    private static function resolver(): NpmResolver
    {
        return (new \ReflectionClass(NpmResolver::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $entries
     *
     * @return list<string>
     */
    private static function sortedKeys(array $entries): array
    {
        $keys = array_keys($entries);
        sort($keys);

        return $keys;
    }
}
