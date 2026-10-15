<?php

namespace App\Resolver;

use App\Blacklist\Blacklist;
use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Http\Downloader;
use App\Job\JobContext;
use App\System\Signatures;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Terraform :
 *  - providers (« hashicorp/aws ~> 5.0 ») : archives des plateformes choisies, sha256 contrôlé contre le
 *    fichier SHA256SUMS dont la signature GPG de l'éditeur est vérifiée ;
 *  - modules (« terraform-aws-modules/vpc/aws 5.8.1 ») : sources GitHub réempaquetées en tar.gz ;
 *  - « terraform ~> 1.9 » : le binaire de Terraform lui-même (releases.hashicorp.com, signé).
 * Un .terraform.lock.hcl importé fournit des versions exactes de providers.
 */
final readonly class TerraformResolver implements Resolver
{
    public const array PLATFORMS = [
        'linux_amd64' => 'Linux amd64',
        'linux_arm64' => 'Linux ARM64',
        'windows_amd64' => 'Windows amd64',
        'darwin_arm64' => 'macOS Apple Silicon',
        'darwin_amd64' => 'macOS Intel',
    ];

    private const string DEFAULT_HOST = 'registry.terraform.io';

    public function __construct(
        private Downloader $downloader,
        private ArtifactCatalog $catalog,
        private Blacklist $blacklist,
        private Signatures $signatures,
        private HttpClientInterface $http,
        private BlobStore $store,
    ) {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Terraform;
    }

    /** @return list<string> Linux amd64 par défaut */
    public static function platforms(PackageList $list): array
    {
        $platforms = $list->getOptions()['platforms'] ?? [];

        return \is_array($platforms) && [] !== $platforms ? array_values($platforms) : ['linux_amd64'];
    }

    /**
     * @return array{kind: string, host: string, address: string, constraint: string}
     *   kind : provider (ns/type), module (ns/nom/fournisseur) ou cli (terraform)
     */
    public static function parseSpec(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), 2) ?: [$line];
        $address = strtolower($parts[0]);
        $constraint = trim($parts[1] ?? '') ?: '*';
        if ('terraform' === $address) {
            return ['kind' => 'cli', 'host' => 'releases.hashicorp.com', 'address' => 'terraform', 'constraint' => $constraint];
        }
        $segments = explode('/', $address);
        // Hôte du registre en tête (registry.terraform.io/hashicorp/aws, registry.opentofu.org/…)
        $host = self::DEFAULT_HOST;
        if (\in_array(\count($segments), [3, 4], true) && str_contains($segments[0], '.')) {
            $host = array_shift($segments);
        }

        return ['kind' => 3 === \count($segments) ? 'module' : (2 === \count($segments) ? 'provider' : 'invalid'), 'host' => $host, 'address' => implode('/', $segments), 'constraint' => $constraint];
    }

    public function validate(PackageList $list): array
    {
        $errors = [];
        foreach ($list->getSpecLines() as $line) {
            $spec = self::parseSpec($line);
            if ('invalid' === $spec['kind'] || !preg_match('#^[a-z0-9][a-z0-9_.-]*(/[a-z0-9][a-z0-9_.-]*)*$#', $spec['address'])) {
                $errors[] = sprintf('« %s » : attendu un provider (hashicorp/aws ~> 5.0), un module (terraform-aws-modules/vpc/aws 5.8.1) ou « terraform ~> 1.9 ».', $line);
            } elseif (!Constraint::isValid($spec['constraint'])) {
                $errors[] = sprintf('« %s » : contrainte de version invalide (ex. ~> 5.0, >= 1.2, < 2.0).', $line);
            }
        }
        foreach (self::platforms($list) as $platform) {
            if (!isset(self::PLATFORMS[$platform])) {
                $errors[] = sprintf('Plateforme inconnue : %s.', $platform);
            }
        }
        if (null !== $list->getLockfile() && [] === self::parseLockFile($list->getLockfile())) {
            $errors[] = 'Le fichier importé n\'est pas un .terraform.lock.hcl (aucun bloc provider).';
        }
        if ([] === $list->getSpecLines() && null === $list->getLockfile()) {
            $errors[] = 'La liste est vide : saisissez des providers (hashicorp/aws ~> 5.0), des modules ou importez un .terraform.lock.hcl.';
        }

        return $errors;
    }

    public function resolve(PackageList $list, JobContext $context): array
    {
        $specs = array_map(self::parseSpec(...), $list->getSpecLines());
        foreach (self::parseLockFile((string) $list->getLockfile()) as $address => $version) {
            $segments = explode('/', $address);
            $specs[] = ['kind' => 'provider', 'host' => 3 === \count($segments) ? array_shift($segments) : self::DEFAULT_HOST, 'address' => implode('/', $segments), 'constraint' => $version];
        }
        $platforms = self::platforms($list);
        $artifacts = [];
        foreach ($specs as $i => $spec) {
            $context->report(sprintf('%s %s', $spec['address'], $spec['constraint']), (int) ($i * 100 / max(1, \count($specs))));
            $found = match ($spec['kind']) {
                'provider' => $this->provider($spec, $platforms, $context),
                'module' => $this->module($spec, $context),
                default => $this->cli($spec['constraint'], $platforms, $context),
            };
            array_push($artifacts, ...$found);
            $this->catalog->flush();
        }

        return $artifacts;
    }

    /**
     * Versions exactes d'un .terraform.lock.hcl.
     *
     * @return array<string, string> adresse (hôte/ns/type) => version
     */
    public static function parseLockFile(string $hcl): array
    {
        preg_match_all('/provider\s+"([^"]+)"\s*\{[^}]*?version\s*=\s*"([^"]+)"/s', $hcl, $m, \PREG_SET_ORDER);
        $providers = [];
        foreach ($m as [, $address, $version]) {
            $providers[strtolower($address)] = $version;
        }

        return $providers;
    }

    /** Points d'accès du registre (découverte de services : /.well-known/terraform.json). */
    private function services(string $host): array
    {
        if (self::DEFAULT_HOST === $host) {
            return ['providers.v1' => 'https://registry.terraform.io/v1/providers/', 'modules.v1' => 'https://registry.terraform.io/v1/modules/'];
        }
        $data = $this->downloader->json(sprintf('https://%s/.well-known/terraform.json', $host));
        $base = sprintf('https://%s', $host);

        return array_map(static fn ($path) => str_starts_with((string) $path, 'http') ? (string) $path : $base.'/'.ltrim((string) $path, '/'), $data);
    }

    /**
     * @param array{host: string, address: string, constraint: string} $spec
     * @param list<string>                                             $platforms
     *
     * @return list<Artifact>
     */
    private function provider(array $spec, array $platforms, JobContext $context): array
    {
        $base = rtrim($this->services($spec['host'])['providers.v1'] ?? throw new \RuntimeException(sprintf('%s n\'est pas un registre de providers.', $spec['host'])), '/');
        $data = $this->downloader->json(sprintf('%s/%s/versions', $base, $spec['address']));
        $available = [];
        foreach ($data['versions'] ?? [] as $item) {
            $available[(string) $item['version']] = array_map(static fn (array $p) => $p['os'].'_'.$p['arch'], $item['platforms'] ?? []);
        }
        $version = Constraint::best(array_keys($available), $spec['constraint'])
            ?? throw new \RuntimeException(sprintf('Aucune version de %s ne respecte « %s ».', $spec['address'], $spec['constraint']));
        $name = $spec['host'].'/'.$spec['address'];
        if ($this->blacklist->blocks(Ecosystem::Terraform, $name, $version, $context)) {
            return [];
        }

        $artifacts = [];
        $sums = null;
        foreach ($platforms as $platform) {
            if (!\in_array($platform, $available[$version], true)) {
                $context->log(sprintf('%s %s n\'existe pas pour %s.', $name, $version, $platform));
                continue;
            }
            [$os, $arch] = explode('_', $platform, 2);
            $download = $this->downloader->json(sprintf('%s/%s/%s/download/%s/%s', $base, $spec['address'], $version, $os, $arch));
            $filename = (string) $download['filename'];
            if ($existing = $this->catalog->complete(Ecosystem::Terraform, $name, $version, $filename)) {
                $artifacts[] = $existing;
                continue;
            }
            // Fichier SHA256SUMS signé par l'éditeur (clé publiée par le registre) : contrôle de l'archive
            if (null === $sums) {
                $sums = $this->text((string) $download['shasums_url']);
                $signature = $this->text((string) $download['shasums_signature_url']);
                $verified = false;
                // Providers HashiCorp : clé livrée avec Rémus (à jour) ; sinon clés publiées par le registre
                try {
                    $this->signatures->detached($sums, $signature, ['hashicorp'], $name.' '.$version);
                    $verified = true;
                } catch (\RuntimeException) {
                }
                foreach ($verified ? [] : $download['signing_keys']['gpg_public_keys'] ?? [] as $key) {
                    try {
                        // Comme Terraform : la date d'expiration de la clé publiée par le registre n'est pas bloquante
                        $this->signatures->detachedWithKey($sums, $signature, (string) $key['ascii_armor'], $name.' '.$version, true);
                        $verified = true;
                        break;
                    } catch (\RuntimeException) {
                    }
                }
                if (!$verified) {
                    throw new \RuntimeException(sprintf('Signature invalide du fichier SHA256SUMS de %s %s : provider refusé.', $name, $version));
                }
            }
            if (!preg_match('/^([a-f0-9]{64})\s+'.preg_quote($filename, '/').'$/m', $sums, $m) || $m[1] !== strtolower((string) $download['shasum'])) {
                throw new \RuntimeException(sprintf('%s absent du fichier SHA256SUMS signé, ou empreinte différente : refusé.', $filename));
            }
            $context->report(sprintf('Téléchargement %s', $filename));
            $blob = $this->downloader->fetch((string) $download['download_url'], sha256: $m[1]);
            $artifacts[] = $this->catalog->record(Ecosystem::Terraform, $name, $version, $filename, [$blob], [
                'kind' => 'provider',
                'host' => $spec['host'],
                'namespace' => explode('/', $spec['address'])[0],
                'type' => explode('/', $spec['address'])[1],
                'os' => $os,
                'arch' => $arch,
                'protocols' => $download['protocols'] ?? [],
            ]);
        }
        $context->log(sprintf('%s %s : %s.', $name, $version, implode(', ', array_map(static fn (Artifact $a) => $a->getMetadata()['os'].'_'.$a->getMetadata()['arch'], $artifacts)) ?: 'aucune plateforme'));

        return $artifacts;
    }

    /**
     * @param array{host: string, address: string, constraint: string} $spec
     *
     * @return list<Artifact>
     */
    private function module(array $spec, JobContext $context): array
    {
        $base = rtrim($this->services($spec['host'])['modules.v1'] ?? throw new \RuntimeException(sprintf('%s n\'est pas un registre de modules.', $spec['host'])), '/');
        $data = $this->downloader->json(sprintf('%s/%s/versions', $base, $spec['address']));
        $versions = array_map(static fn (array $v) => (string) $v['version'], $data['modules'][0]['versions'] ?? []);
        $version = Constraint::best($versions, $spec['constraint'])
            ?? throw new \RuntimeException(sprintf('Aucune version du module %s ne respecte « %s ».', $spec['address'], $spec['constraint']));
        $name = $spec['address'];
        if ($this->blacklist->blocks(Ecosystem::Terraform, $name, $version, $context)) {
            return [];
        }
        $filename = sprintf('modules/%s-%s.tar.gz', str_replace('/', '-', $name), $version);
        if ($existing = $this->catalog->complete(Ecosystem::Terraform, $name, $version, $filename)) {
            return [$existing];
        }

        $response = $this->http->request('GET', sprintf('%s/%s/%s/download', $base, $spec['address'], $version), ['timeout' => 30]);
        $get = $response->getHeaders(false)['x-terraform-get'][0] ?? throw new \RuntimeException(sprintf('Pas d\'adresse de téléchargement pour le module %s %s.', $name, $version));
        $archive = self::archiveUrl($get) ?? throw new \RuntimeException(sprintf('Source du module %s non prise en charge : %s (seuls GitHub et les archives .tar.gz/.zip le sont).', $name, $get));
        $context->report(sprintf('Téléchargement du module %s %s', $name, $version));
        $blob = $this->repack($archive['url'], $archive['subdir'], $archive['strip'], $context);
        $context->log(sprintf('Module %s %s (les modules qu\'il appelle lui-même ne sont pas suivis : ajoutez-les à la liste).', $name, $version));

        return [$this->catalog->record(Ecosystem::Terraform, $name, $version, $filename, [$blob], [
            'kind' => 'module',
            'host' => $spec['host'],
            'source' => $get,
        ])];
    }

    /**
     * Adresse d'archive d'une source de module : git::https://github.com/o/r//sous/dossier?ref=x → codeload.
     *
     * @return array{url: string, subdir: string, strip: bool}|null
     */
    public static function archiveUrl(string $get): ?array
    {
        if (preg_match('#^(?:git::)?https://github\.com/([^/]+)/([^/?]+?)(?:\.git)?(?://([^?]*))?(?:\?ref=([^&]+))?$#', $get, $m)) {
            return ['url' => sprintf('https://codeload.github.com/%s/%s/tar.gz/%s', $m[1], $m[2], ($m[4] ?? '') ?: 'HEAD'), 'subdir' => trim($m[3] ?? '', '/'), 'strip' => true];
        }
        if (preg_match('#^(https?://[^?]+\.(?:tar\.gz|tgz))(?://([^?]*))?#', $get, $m)) {
            return ['url' => $m[1], 'subdir' => trim($m[2] ?? '', '/'), 'strip' => false];
        }

        return null;
    }

    /**
     * Archive réempaquetée, module à la racine : utilisable directement comme source HTTP.
     *
     * @return array{sha256: string, size: int}
     */
    private function repack(string $url, string $subdir, bool $strip, JobContext $context): array
    {
        $dir = Tool::tempDir('sas-tfmod');
        try {
            $source = $this->downloader->fetch($url);
            copy($this->store->path($source['sha256']), $dir.'/in.tar.gz');
            mkdir($dir.'/x');
            Tool::run(['tar', '-xzf', $dir.'/in.tar.gz', '-C', $dir.'/x'], $dir, $context);
            $root = $dir.'/x';
            if ($strip) {
                $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
                $root .= 1 === \count($entries) ? '/'.$entries[0] : '';
            }
            if ('' !== $subdir) {
                $root .= '/'.$subdir;
            }
            if (!is_dir($root) || str_contains($subdir, '..')) {
                throw new \RuntimeException(sprintf('Sous-dossier « %s » absent de l\'archive du module.', $subdir));
            }
            Tool::run(['tar', '-czf', $dir.'/out.tar.gz', '-C', $root, '.'], $dir, $context);

            // Copie dans le dossier temporaire du stockage : un déplacement entre volumes échouerait
            $tmp = $this->store->tempFile();
            copy($dir.'/out.tar.gz', $tmp);

            return $this->store->putFile($tmp);
        } finally {
            Tool::removeDir($dir);
        }
    }

    /**
     * @param list<string> $platforms
     *
     * @return list<Artifact>
     */
    private function cli(string $constraint, array $platforms, JobContext $context): array
    {
        $index = $this->downloader->json('https://releases.hashicorp.com/terraform/index.json');
        $version = Constraint::best(array_keys($index['versions'] ?? []), $constraint)
            ?? throw new \RuntimeException(sprintf('Aucune version de Terraform ne respecte « %s ».', $constraint));
        if ($this->blacklist->blocks(Ecosystem::Terraform, 'terraform', $version, $context)) {
            return [];
        }
        $base = sprintf('https://releases.hashicorp.com/terraform/%s/terraform_%s_SHA256SUMS', $version, $version);
        $sums = $this->text($base);
        $this->signatures->detached($sums, $this->text($base.'.sig'), ['hashicorp'], 'Terraform '.$version);
        $artifacts = [];
        foreach ($index['versions'][$version]['builds'] ?? [] as $build) {
            $platform = $build['os'].'_'.$build['arch'];
            if (!\in_array($platform, $platforms, true)) {
                continue;
            }
            $filename = (string) $build['filename'];
            if ($existing = $this->catalog->complete(Ecosystem::Terraform, 'terraform', $version, $filename)) {
                $artifacts[] = $existing;
                continue;
            }
            if (!preg_match('/^([a-f0-9]{64})\s+'.preg_quote($filename, '/').'$/m', $sums, $m)) {
                throw new \RuntimeException(sprintf('%s absent du fichier SHA256SUMS signé.', $filename));
            }
            $context->report('Téléchargement '.$filename);
            $blob = $this->downloader->fetch((string) $build['url'], sha256: $m[1]);
            $artifacts[] = $this->catalog->record(Ecosystem::Terraform, 'terraform', $version, $filename, [$blob], [
                'kind' => 'cli', 'os' => $build['os'], 'arch' => $build['arch'],
            ]);
        }
        $context->log(sprintf('Terraform %s : %d archive(s).', $version, \count($artifacts)));

        return $artifacts;
    }

    private function text(string $url): string
    {
        $response = $this->http->request('GET', $url, ['timeout' => 60]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('HTTP %d pour %s.', $response->getStatusCode(), $url));
        }

        return $response->getContent();
    }
}
