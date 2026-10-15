<?php

namespace App\Resolver;

use App\Entity\PackageList;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;

/** Options d'une liste Debian ou RPM : distribution, architectures, sections (dépôts), recommandés. */
final class SystemOptions
{
    public static function distribution(PackageList $list): string
    {
        $id = (string) ($list->getOptions()['distribution'] ?? '');

        return '' !== $id ? $id : array_key_first(Distributions::of($list->getEcosystem()));
    }

    /** @return list<string> Linux amd64 / x86_64 par défaut */
    public static function architectures(PackageList $list): array
    {
        $archs = $list->getOptions()['architectures'] ?? [];
        if (\is_array($archs) && [] !== $archs) {
            return array_values(array_unique(array_map('strval', $archs)));
        }

        return [Ecosystem::Deb === $list->getEcosystem() ? 'amd64' : 'x86_64'];
    }

    /** @return list<string> */
    public static function sections(PackageList $list): array
    {
        $sections = $list->getOptions()['sections'] ?? [];
        if (\is_array($sections) && [] !== $sections) {
            return array_values(array_unique(array_map('strval', $sections)));
        }
        $dist = Distributions::find(self::distribution($list));

        return array_keys(array_filter($dist['sections'] ?? [], static fn (array $s) => $s['default']));
    }

    public static function recommends(PackageList $list): bool
    {
        return (bool) ($list->getOptions()['recommends'] ?? false);
    }

    /** @return list<string> */
    public static function validate(PackageList $list): array
    {
        $errors = [];
        $dist = Distributions::find(self::distribution($list));
        if (!$dist || $dist['ecosystem'] !== $list->getEcosystem()) {
            return ['Distribution inconnue.'];
        }
        foreach (self::architectures($list) as $arch) {
            if (!isset($dist['architectures'][$arch])) {
                $errors[] = sprintf('Architecture %s non disponible pour %s.', $arch, $dist['label']);
            }
        }
        foreach (self::sections($list) as $section) {
            if (!isset($dist['sections'][$section])) {
                $errors[] = sprintf('Section ou dépôt %s inconnu pour %s.', $section, $dist['label']);
            }
        }
        if ([] === $list->getSpecLines()) {
            $errors[] = 'La liste est vide : saisissez des paquets (ex. nginx, postgresql, git).';
        }

        return $errors;
    }
}
