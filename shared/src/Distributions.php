<?php

namespace Sas\Bundle;

/**
 * Distributions Linux prises en charge (dépôts apt et dnf/yum), communes à Rémus (adresses, clés de
 * signature) et à Romulus (nom de code, architectures servies).
 *
 * Debian, Ubuntu : les suites (principale, -updates, -security) sont fusionnées en une seule chez Romulus.
 * Ubuntu publie arm64 ailleurs qu'amd64 (« ports » : ports.ubuntu.com).
 * RPM : les dépôts (BaseOS, AppStream…) sont fusionnés en un seul dépôt par distribution et architecture.
 * Distribution personnalisée : clé fournie en ASCII armor (« key ») au lieu des clés livrées (« keys »).
 *
 * @phpstan-type Distribution array{
 *   ecosystem: Ecosystem, label: string, codename: string, release: string, eol: bool,
 *   architectures: array<string, string>, sections: array<string, array{label: string, default: bool}>,
 *   suites?: list<array{url: string, ports?: string, suite: string, keys: list<string>, key?: string}>,
 *   repos?: array<string, array{url: string, keys: list<string>, key?: string}>,
 *   clientKey?: string, modules?: bool, custom?: bool,
 * }
 */
final class Distributions
{
    /**
     * Distributions ajoutées par l'application : Rémus (Réglages › Distributions personnalisées), Romulus
     * (apprises des paquets importés). Branché au démarrage du noyau, retiré à son arrêt.
     *
     * @var (\Closure(): array<string, Distribution>)|null
     */
    private static ?\Closure $extra = null;

    /** @param (\Closure(): array<string, Distribution>)|null $provider */
    public static function extend(?\Closure $provider): void
    {
        self::$extra = $provider;
    }

    /**
     * Distributions intégrées, puis personnalisées (un identifiant intégré n'est jamais remplacé).
     *
     * @return array<string, Distribution>
     */
    public static function all(): array
    {
        return self::builtIn() + (null !== self::$extra ? (self::$extra)() : []);
    }

    /**
     * Description d'une distribution personnalisée, transmise à Romulus dans les métadonnées de ses paquets
     * (« distributionInfo ») ; null pour une distribution intégrée, que Romulus connaît déjà.
     *
     * @param Distribution|null $dist
     *
     * @return array{ecosystem: string, label: string, codename: string, release: string, eol: bool, architectures: list<string>, sections: list<string>}|null
     */
    public static function info(?array $dist): ?array
    {
        if (null === $dist || !($dist['custom'] ?? false)) {
            return null;
        }

        return [
            'ecosystem' => $dist['ecosystem']->value,
            'label' => $dist['label'],
            'codename' => $dist['codename'],
            'release' => $dist['release'],
            'eol' => $dist['eol'],
            'architectures' => array_map('strval', array_keys($dist['architectures'])),
            'sections' => array_map('strval', array_keys($dist['sections'])),
        ];
    }

    public static function isBuiltIn(string $id): bool
    {
        return isset(self::builtIn()[$id]);
    }

    /** @return array<string, Distribution> */
    public static function builtIn(): array
    {
        $debianSections = [
            'main' => ['label' => 'main', 'default' => true],
            'contrib' => ['label' => 'contrib', 'default' => false],
            'non-free' => ['label' => 'non-free', 'default' => false],
            'non-free-firmware' => ['label' => 'non-free-firmware (micrologiciels)', 'default' => false],
        ];
        $debian = static fn (string $version, string $codename, bool $eol = false) => [
            'ecosystem' => Ecosystem::Deb,
            'label' => $eol ? sprintf('Debian %s (%s, fin de vie)', $version, $codename) : sprintf('Debian %s (%s)', $version, $codename),
            'codename' => $codename,
            'release' => $version,
            'eol' => $eol,
            'architectures' => ['amd64' => 'amd64 (x86_64)', 'arm64' => 'arm64 (aarch64)'],
            // non-free-firmware n'existe qu'à partir de Debian 12
            'sections' => (int) $version >= 12 ? $debianSections : array_diff_key($debianSections, ['non-free-firmware' => true]),
            'suites' => [
                ['url' => 'https://deb.debian.org/debian', 'suite' => $codename, 'keys' => ['archive-key-'.$version, 'release-'.$version]],
                ['url' => 'https://deb.debian.org/debian', 'suite' => $codename.'-updates', 'keys' => ['archive-key-'.$version, 'release-'.$version]],
                ['url' => 'https://security.debian.org/debian-security', 'suite' => $codename.'-security', 'keys' => ['archive-key-'.$version.'-security']],
            ],
        ];
        $ubuntu = static fn (string $version, string $codename) => [
            'ecosystem' => Ecosystem::Deb,
            'label' => sprintf('Ubuntu %s LTS (%s)', $version, $codename),
            'codename' => $codename,
            'release' => $version,
            'eol' => false,
            'architectures' => ['amd64' => 'amd64 (x86_64)', 'arm64' => 'arm64 (aarch64)'],
            'sections' => [
                'main' => ['label' => 'main', 'default' => true],
                'restricted' => ['label' => 'restricted (pilotes propriétaires)', 'default' => false],
                'universe' => ['label' => 'universe (communauté)', 'default' => true],
                'multiverse' => ['label' => 'multiverse (non libre)', 'default' => false],
            ],
            'suites' => [
                ['url' => 'https://archive.ubuntu.com/ubuntu', 'ports' => 'https://ports.ubuntu.com/ubuntu-ports', 'suite' => $codename, 'keys' => ['ubuntu-archive-2018']],
                ['url' => 'https://archive.ubuntu.com/ubuntu', 'ports' => 'https://ports.ubuntu.com/ubuntu-ports', 'suite' => $codename.'-updates', 'keys' => ['ubuntu-archive-2018']],
                ['url' => 'https://security.ubuntu.com/ubuntu', 'ports' => 'https://ports.ubuntu.com/ubuntu-ports', 'suite' => $codename.'-security', 'keys' => ['ubuntu-archive-2018']],
            ],
        ];
        $el = static fn (string $label, string $release, string $base, array $repos, array $keys, string $clientKey, bool $eol = false, array $arches = ['x86_64' => 'x86_64', 'aarch64' => 'aarch64']) => [
            'ecosystem' => Ecosystem::Rpm,
            'label' => $label,
            'codename' => $release,
            'release' => $release,
            'eol' => $eol,
            'architectures' => $arches,
            'sections' => array_map(static fn (array $r) => ['label' => $r[0], 'default' => $r[1]], $repos),
            'repos' => array_map(static fn (array $r) => ['url' => $base.'/'.$r[2], 'keys' => $keys], $repos),
            'clientKey' => $clientKey,
            'modules' => \in_array($release, ['8', '9'], true),
        ];

        return [
            'debian-11' => $debian('11', 'bullseye', eol: true),
            'debian-12' => $debian('12', 'bookworm'),
            'debian-13' => $debian('13', 'trixie'),
            'ubuntu-2204' => $ubuntu('22.04', 'jammy'),
            'ubuntu-2404' => $ubuntu('24.04', 'noble'),
            'almalinux-8' => $el('AlmaLinux 8', '8', 'https://repo.almalinux.org/almalinux/8', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'PowerTools' => ['PowerTools (outils de développement)', false, 'PowerTools/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['almalinux-8'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-AlmaLinux'),
            'almalinux-9' => $el('AlmaLinux 9', '9', 'https://repo.almalinux.org/almalinux/9', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['almalinux-9'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-AlmaLinux-9'),
            'almalinux-10' => $el('AlmaLinux 10', '10', 'https://repo.almalinux.org/almalinux/10', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['almalinux-10'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-AlmaLinux-10',
                arches: ['x86_64' => 'x86_64 (x86-64-v3)', 'x86_64_v2' => 'x86_64_v2 (processeurs plus anciens)', 'aarch64' => 'aarch64']),
            'rocky-8' => $el('Rocky Linux 8', '8', 'https://dl.rockylinux.org/pub/rocky/8', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'PowerTools' => ['PowerTools (outils de développement)', false, 'PowerTools/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['rocky-8'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-rockyofficial'),
            'rocky-9' => $el('Rocky Linux 9', '9', 'https://dl.rockylinux.org/pub/rocky/9', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['rocky-9'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-Rocky-9'),
            'rocky-10' => $el('Rocky Linux 10', '10', 'https://dl.rockylinux.org/pub/rocky/10', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['rocky-10'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-Rocky-10',
                arches: ['x86_64' => 'x86_64 (x86-64-v3)', 'aarch64' => 'aarch64']),
            'centos-stream-9' => $el('CentOS Stream 9', '9', 'https://mirror.stream.centos.org/9-stream', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
            ], ['centos-official-sha256', 'centos-official'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-centosofficial'),
            'centos-stream-10' => $el('CentOS Stream 10', '10', 'https://mirror.stream.centos.org/10-stream', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'CRB' => ['CRB (outils de développement)', false, 'CRB/$arch/os'],
            ], ['centos-official-sha256', 'centos-official'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-centosofficial-SHA256'),
            'centos-8' => $el('CentOS 8.5 (fin de vie, vault)', '8', 'https://vault.centos.org/8.5.2111', [
                'BaseOS' => ['BaseOS', true, 'BaseOS/$arch/os'],
                'AppStream' => ['AppStream', true, 'AppStream/$arch/os'],
                'PowerTools' => ['PowerTools', false, 'PowerTools/$arch/os'],
                'extras' => ['extras', false, 'extras/$arch/os'],
            ], ['centos-official'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-centosofficial', true),
            'centos-7' => $el('CentOS 7.9 (fin de vie, vault)', '7', 'https://vault.centos.org/7.9.2009', [
                'os' => ['os', true, 'os/$arch'],
                'updates' => ['updates', true, 'updates/$arch'],
                'extras' => ['extras', false, 'extras/$arch'],
            ], ['centos-7'], '/etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7', true, ['x86_64' => 'x86_64']),
        ];
    }

    /** @return Distribution|null */
    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** @return array<string, Distribution> */
    public static function of(Ecosystem $ecosystem): array
    {
        return array_filter(self::all(), static fn (array $d) => $d['ecosystem'] === $ecosystem);
    }
}
