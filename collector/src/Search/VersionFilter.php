<?php

namespace App\Search;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Garde les paquets qui ont au moins une version respectant la contrainte, selon les règles exactes
 * de chaque écosystème : semver de npm, composer/semver, specifiers de pip (packaging).
 * Sert à développer un motif sans y inclure des paquets qui feraient échouer toute la résolution
 * (ex. symfony/acl-bundle n'existe pas en ^7.3).
 */
final readonly class VersionFilter
{
    private const array NPM_SEMVER = [
        '/usr/lib/node_modules/npm/node_modules/semver',
        '/usr/local/lib/node_modules/npm/node_modules/semver',
    ];

    public function __construct(
        #[Autowire('%env(PIP_BIN)%')]
        private string $pip,
    ) {
    }

    /**
     * @param array<string, list<string>> $versions versions publiées par paquet
     *
     * @return array{keep: list<string>, skipped: list<string>}
     */
    public function filter(Ecosystem $ecosystem, array $versions, string $constraint): array
    {
        $keep = match ($ecosystem) {
            Ecosystem::Npm => $this->npm($versions, $constraint),
            Ecosystem::Composer => self::composer($versions, $constraint),
            Ecosystem::Pypi => $this->pypi($versions, $constraint),
            Ecosystem::Docker => array_keys($versions),
        };

        return ['keep' => array_values($keep), 'skipped' => array_values(array_diff(array_keys($versions), $keep))];
    }

    /**
     * @param array<string, list<string>> $versions
     *
     * @return list<string>
     */
    private function npm(array $versions, string $range): array
    {
        $semver = null;
        foreach (self::NPM_SEMVER as $path) {
            if (is_file($path.'/package.json')) {
                $semver = $path;
                break;
            }
        }
        if (null === $semver) {
            return array_keys($versions); // npm absent : pas de filtrage, npm signalera l'erreur
        }
        $script = 'const semver = require(process.argv[1]); let input = "";'
            .'process.stdin.on("data", d => input += d).on("end", () => { const { range, packages } = JSON.parse(input);'
            .'console.log(JSON.stringify(Object.keys(packages).filter(n => semver.maxSatisfying(packages[n], range) !== null))); });';

        return $this->run(['node', '-e', $script, $semver], ['range' => $range, 'packages' => $versions]);
    }

    /**
     * @param array<string, list<string>> $versions
     *
     * @return list<string>
     */
    private static function composer(array $versions, string $constraint): array
    {
        $keep = [];
        foreach ($versions as $name => $list) {
            // Versions stables seulement, comme la résolution (minimum-stability: stable)
            $stable = array_values(array_filter($list, static fn (string $v) => 'stable' === VersionParser::parseStability($v)));
            try {
                if ([] !== Semver::satisfiedBy($stable, $constraint)) {
                    $keep[] = $name;
                }
            } catch (\UnexpectedValueException) {
                throw new \InvalidArgumentException(sprintf('Contrainte de version invalide : « %s ».', $constraint));
            }
        }

        return $keep;
    }

    /**
     * @param array<string, list<string>> $versions
     *
     * @return list<string>
     */
    private function pypi(array $versions, string $specifier): array
    {
        $python = \dirname($this->pip).'/python';
        if (!is_file($python)) {
            return array_keys($versions);
        }
        $script = 'import json, sys'."\n"
            .'from pip._vendor.packaging.specifiers import SpecifierSet'."\n"
            .'from pip._vendor.packaging.version import InvalidVersion, Version'."\n"
            .'data = json.load(sys.stdin); spec = SpecifierSet(data["spec"]); keep = []'."\n"
            .'for name, versions in data["packages"].items():'."\n"
            .'    ok = []'."\n"
            .'    for v in versions:'."\n"
            .'        try: ok.append(Version(v))'."\n"
            .'        except InvalidVersion: pass'."\n"
            .'    if list(spec.filter(ok)): keep.append(name)'."\n"
            .'print(json.dumps(keep))';

        return $this->run([$python, '-c', $script], ['spec' => $specifier, 'packages' => $versions]);
    }

    /**
     * @param list<string>         $command
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private function run(array $command, array $input): array
    {
        // Paquets en objet JSON (même vide), listes de versions en tableaux JSON
        $input['packages'] = (object) array_map('array_values', $input['packages']);
        $process = new Process($command, null, null, json_encode($input, \JSON_THROW_ON_ERROR), 120);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \InvalidArgumentException('Contrainte de version invalide : '.trim(strtok($process->getErrorOutput(), "\n") ?: 'erreur inconnue'));
        }

        return json_decode(trim($process->getOutput()), true, flags: \JSON_THROW_ON_ERROR);
    }
}
