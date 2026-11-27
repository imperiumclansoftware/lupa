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
            Ecosystem::Docker, Ecosystem::Deb, Ecosystem::Rpm, Ecosystem::Galaxy, Ecosystem::Terraform, Ecosystem::Vscode => array_keys($versions),
        };

        return ['keep' => array_values($keep), 'skipped' => array_values(array_diff(array_keys($versions), $keep))];
    }

    /**
     * Versions stables d'un paquet qui respectent la contrainte, dans l'ordre croissant (plage « [toutes] »).
     * Préversions exclues, comme le font npm, Composer et pip par défaut.
     *
     * @param list<string> $versions versions publiées
     *
     * @return list<string>
     */
    public function matching(Ecosystem $ecosystem, array $versions, string $constraint): array
    {
        $versions = array_values(array_unique($versions));

        return match ($ecosystem) {
            Ecosystem::Npm => $this->npmMatching($versions, $constraint),
            Ecosystem::Composer => self::composerMatching($versions, $constraint),
            Ecosystem::Pypi => $this->pypiMatching($versions, $constraint),
            default => throw new \LogicException('Sans objet pour cet écosystème.'),
        };
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private function npmMatching(array $versions, string $range): array
    {
        $semver = $this->npmSemver() ?? throw new \RuntimeException('npm est introuvable : impossible d\'évaluer la plage de versions.');
        $script = 'const semver = require(process.argv[1]); let input = "";'
            .'process.stdin.on("data", d => input += d).on("end", () => { const { range, packages } = JSON.parse(input);'
            .'if (semver.validRange(range) === null) { console.error("plage semver invalide : " + range); process.exit(1); }'
            .'console.log(JSON.stringify(semver.sort(packages.v.filter(v => semver.valid(v) && semver.satisfies(v, range))))); });';

        return $this->run(['node', '-e', $script, $semver], ['range' => $range, 'packages' => ['v' => $versions]]);
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private static function composerMatching(array $versions, string $constraint): array
    {
        $stable = array_values(array_filter($versions, static function (string $v): bool {
            try {
                return 'stable' === VersionParser::parseStability($v) && !str_starts_with($v, 'dev-');
            } catch (\UnexpectedValueException) {
                return false;
            }
        }));
        try {
            return Semver::sort(Semver::satisfiedBy($stable, $constraint));
        } catch (\UnexpectedValueException) {
            throw new \InvalidArgumentException(sprintf('Contrainte de version invalide : « %s ».', $constraint));
        }
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private function pypiMatching(array $versions, string $specifier): array
    {
        $python = \dirname($this->pip).'/python';
        if (!is_file($python)) {
            throw new \RuntimeException('pip est introuvable : impossible d\'évaluer la plage de versions.');
        }
        $script = 'import json, sys'."\n"
            .'from pip._vendor.packaging.specifiers import SpecifierSet'."\n"
            .'from pip._vendor.packaging.version import InvalidVersion, Version'."\n"
            .'data = json.load(sys.stdin); spec = SpecifierSet(data["spec"]); ok = {}'."\n"
            .'for v in data["packages"]["v"]:'."\n"
            .'    try: ok[Version(v)] = v'."\n"
            .'    except InvalidVersion: pass'."\n"
            .'print(json.dumps([ok[v] for v in sorted(spec.filter(ok.keys()))]))';

        return $this->run([$python, '-c', $script], ['spec' => $specifier, 'packages' => ['v' => $versions]]);
    }

    private function npmSemver(): ?string
    {
        foreach (self::NPM_SEMVER as $path) {
            if (is_file($path.'/package.json')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $versions
     *
     * @return list<string>
     */
    private function npm(array $versions, string $range): array
    {
        $semver = $this->npmSemver();
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
