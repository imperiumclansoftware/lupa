<?php

namespace Sas\Bundle;

/**
 * Identité « paquet + version » commune au collecteur et au miroir (liste noire, quarantaine) :
 *   - npm, Composer : nom en minuscules ; Composer sans « v » initial (v5.4.0 = 5.4.0) ;
 *   - Python : nom normalisé PEP 503 (Django = django, ruamel_yaml = ruamel-yaml) et zéros finaux
 *     ignorés (4.2 = 4.2.0, comme PEP 440) ;
 *   - Docker : nom servi par le miroir (nginx = library/nginx) et étiquette ;
 *   - Debian, RPM : époque nulle omise (0:1.2-3 = 1.2-3).
 */
final class PackageKey
{
    public static function of(Ecosystem $ecosystem, string $name, string $version): string
    {
        return $ecosystem->value."\0".self::name($ecosystem, $name)."\0".self::version($ecosystem, $version);
    }

    public static function name(Ecosystem $ecosystem, string $name): string
    {
        $name = trim($name);

        return match ($ecosystem) {
            Ecosystem::Pypi => strtolower((string) preg_replace('/[-_.]+/', '-', $name)),
            Ecosystem::Docker => self::dockerName(strtolower($name)),
            // Provider Terraform sans hôte : registre public (hashicorp/aws = registry.terraform.io/hashicorp/aws)
            Ecosystem::Terraform => 1 === substr_count($name, '/') ? 'registry.terraform.io/'.strtolower($name) : strtolower($name),
            default => strtolower($name),
        };
    }

    public static function version(Ecosystem $ecosystem, string $version): string
    {
        $version = trim($version);

        return match ($ecosystem) {
            Ecosystem::Composer => ltrim(strtolower($version), 'v'),
            Ecosystem::Pypi => (string) preg_replace('/(\.0+)+$/', '', strtolower($version)),
            // Époque nulle implicite : « 0:1.2-3 » = « 1.2-3 »
            Ecosystem::Deb, Ecosystem::Rpm => (string) preg_replace('/^0+:/', '', $version),
            default => $version,
        };
    }

    /** nginx → library/nginx ; docker.io/x/y → x/y ; ghcr.io/o/a reste tel quel */
    private static function dockerName(string $name): string
    {
        $name = (string) preg_replace('#^(docker\.io|index\.docker\.io|registry-1\.docker\.io)/#', '', $name);
        return str_contains($name, '/') ? $name : 'library/'.$name;
    }
}
