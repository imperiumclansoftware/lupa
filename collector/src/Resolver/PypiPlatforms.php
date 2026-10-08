<?php

namespace App\Resolver;

/**
 * Plateformes cibles proposées pour Python, traduites en étiquettes de wheels pour pip (--platform).
 * pip n'accepte que les wheels dont l'étiquette figure dans la liste : on énumère donc les variantes compatibles.
 */
final class PypiPlatforms
{
    public const array PYTHON_VERSIONS = ['3.9', '3.10', '3.11', '3.12', '3.13', '3.14'];

    /** @return array<string, string> identifiant => libellé */
    public static function labels(): array
    {
        return [
            'linux-x86_64' => 'Linux x86_64 (glibc ≥ 2.17 : Debian, Ubuntu, RHEL…)',
            'linux-aarch64' => 'Linux ARM64 (glibc ≥ 2.17)',
            'musllinux-x86_64' => 'Linux x86_64 musl (Alpine)',
            'musllinux-aarch64' => 'Linux ARM64 musl (Alpine)',
            'windows-amd64' => 'Windows 64 bits',
            'macos-arm64' => 'macOS Apple Silicon (≥ 11)',
            'macos-x86_64' => 'macOS Intel (≥ 10.9)',
        ];
    }

    /** @return list<string> */
    public static function tags(string $platform): array
    {
        return match ($platform) {
            'linux-x86_64' => self::manylinux('x86_64', true),
            'linux-aarch64' => self::manylinux('aarch64', false),
            'musllinux-x86_64' => ['musllinux_1_2_x86_64', 'musllinux_1_1_x86_64'],
            'musllinux-aarch64' => ['musllinux_1_2_aarch64', 'musllinux_1_1_aarch64'],
            'windows-amd64' => ['win_amd64'],
            'macos-arm64' => self::macos(['arm64', 'universal2']),
            'macos-x86_64' => self::macos(['x86_64', 'intel', 'universal2', 'universal']),
            default => throw new \InvalidArgumentException(sprintf('Plateforme Python inconnue : %s.', $platform)),
        };
    }

    /** @return list<string> */
    private static function manylinux(string $arch, bool $legacy): array
    {
        $tags = [];
        for ($minor = 39; $minor >= 17; --$minor) {
            $tags[] = sprintf('manylinux_2_%d_%s', $minor, $arch);
        }
        $tags[] = 'manylinux2014_'.$arch;
        if ($legacy) {
            array_push($tags, 'manylinux_2_12_'.$arch, 'manylinux2010_'.$arch, 'manylinux_2_5_'.$arch, 'manylinux1_'.$arch);
        }

        return $tags;
    }

    /**
     * @param list<string> $archs
     *
     * @return list<string>
     */
    private static function macos(array $archs): array
    {
        $versions = [];
        for ($major = 15; $major >= 11; --$major) {
            $versions[] = $major.'_0';
        }
        for ($minor = 16; $minor >= 9; --$minor) {
            $versions[] = '10_'.$minor;
        }
        $tags = [];
        foreach ($versions as $version) {
            foreach ($archs as $arch) {
                // Pas de wheels arm64 avant macOS 11 ; les universal2 marquées 10.x conviennent
                if ('arm64' === $arch && str_starts_with($version, '10_')) {
                    continue;
                }
                $tags[] = sprintf('macosx_%s_%s', $version, $arch);
            }
        }

        return $tags;
    }
}
