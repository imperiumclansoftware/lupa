<?php

namespace App\System;

/**
 * Convertit un primary.xml (dépôts sans base SQLite : createrepo_c récent, Docker, Microsoft…) en base au
 * schéma de primary_db (packages, dépendances, files), seul format que lit RpmRepository.
 */
final class PrimaryXml
{
    private const string COMMON = 'http://linux.duke.edu/metadata/common';
    private const string RPM = 'http://linux.duke.edu/metadata/rpm';
    private const array DEPENDENCIES = ['provides', 'requires', 'conflicts', 'obsoletes', 'suggests', 'enhances', 'recommends', 'supplements'];
    private const array COLUMNS = ['pkgId', 'name', 'arch', 'version', 'epoch', 'release', 'summary', 'description', 'url', 'time_file', 'time_build', 'rpm_license', 'rpm_vendor', 'rpm_group', 'rpm_buildhost', 'rpm_sourcerpm', 'rpm_header_start', 'rpm_header_end', 'rpm_packager', 'size_package', 'size_installed', 'size_archive', 'location_href', 'location_base', 'checksum_type'];

    /** Base SQLite à côté du fichier XML (réutilisée tant que l'index ne change pas). */
    public static function database(string $xmlFile): string
    {
        $target = $xmlFile.'.db';
        if (is_file($target)) {
            return $target;
        }
        $tmp = $target.'.'.bin2hex(random_bytes(4));
        try {
            self::convert($xmlFile, $tmp);
            rename($tmp, $target);
        } finally {
            @unlink($tmp);
        }

        return $target;
    }

    public static function convert(string $xmlFile, string $target): void
    {
        $pdo = new \PDO('sqlite:'.$target, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = OFF; PRAGMA synchronous = OFF');
        $pdo->exec('CREATE TABLE packages (pkgKey INTEGER PRIMARY KEY, '.implode(', ', array_map(static fn (string $c) => $c.' TEXT', self::COLUMNS)).')');
        foreach (self::DEPENDENCIES as $table) {
            $pdo->exec("CREATE TABLE $table (name TEXT, flags TEXT, epoch TEXT, version TEXT, release TEXT, pkgKey INTEGER".('requires' === $table ? ', pre BOOLEAN DEFAULT FALSE' : '').')');
        }
        $pdo->exec('CREATE TABLE files (name TEXT, type TEXT, pkgKey INTEGER)');

        $insert = $pdo->prepare(sprintf('INSERT INTO packages (pkgKey, %s) VALUES (?%s)', implode(', ', self::COLUMNS), str_repeat(', ?', \count(self::COLUMNS))));
        $dependency = [];
        foreach (self::DEPENDENCIES as $table) {
            $dependency[$table] = $pdo->prepare("INSERT INTO $table (name, flags, epoch, version, release, pkgKey".('requires' === $table ? ', pre' : '').') VALUES (?, ?, ?, ?, ?, ?'.('requires' === $table ? ', ?' : '').')');
        }
        $file = $pdo->prepare('INSERT INTO files (name, type, pkgKey) VALUES (?, ?, ?)');

        $reader = new \XMLReader();
        if (!$reader->open($xmlFile, null, \LIBXML_NONET | \LIBXML_COMPACT)) {
            throw new \RuntimeException('primary.xml illisible.');
        }
        $pdo->beginTransaction();
        $key = 0;
        while ($reader->read()) {
            if (\XMLReader::ELEMENT !== $reader->nodeType || 'package' !== $reader->localName) {
                continue;
            }
            $node = $reader->expand(new \DOMDocument());
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $p = self::package($node);
            $insert->execute([++$key, ...array_map(static fn (string $c) => $p[$c] ?? null, self::COLUMNS)]);
            // Dépendances et fichiers : dans l'élément <format>
            $format = self::child($node, self::COMMON, 'format');
            foreach (self::DEPENDENCIES as $table) {
                foreach (self::children($format, self::RPM, $table) as $list) {
                    foreach (self::children($list, self::RPM, 'entry') as $entry) {
                        $values = [$entry->getAttribute('name'), self::attribute($entry, 'flags'), self::attribute($entry, 'epoch'), self::attribute($entry, 'ver'), self::attribute($entry, 'rel'), $key];
                        if ('requires' === $table) {
                            $values[] = \in_array($entry->getAttribute('pre'), ['1', 'true', 'TRUE'], true) ? 'TRUE' : 'FALSE';
                        }
                        $dependency[$table]->execute($values);
                    }
                }
            }
            foreach (self::children($format, self::COMMON, 'file') as $entry) {
                $file->execute([$entry->textContent, $entry->getAttribute('type') ?: 'file', $key]);
            }
            $reader->next();
        }
        $reader->close();
        $pdo->exec('CREATE INDEX packagename ON packages (name); CREATE INDEX providesname ON provides (name); CREATE INDEX filenames ON files (name)');
        foreach ([...self::DEPENDENCIES, 'files'] as $table) {
            $pdo->exec("CREATE INDEX {$table}_pkgkey ON $table (pkgKey)");
        }
        $pdo->commit();
    }

    /** @return array<string, string|null> */
    private static function package(\DOMElement $node): array
    {
        $text = static fn (?\DOMElement $e) => $e?->textContent;
        $version = self::child($node, self::COMMON, 'version');
        $checksum = self::child($node, self::COMMON, 'checksum');
        $time = self::child($node, self::COMMON, 'time');
        $size = self::child($node, self::COMMON, 'size');
        $location = self::child($node, self::COMMON, 'location');
        $format = self::child($node, self::COMMON, 'format');
        $range = self::child($format, self::RPM, 'header-range');

        return [
            'pkgId' => $text($checksum),
            'name' => $text(self::child($node, self::COMMON, 'name')),
            'arch' => $text(self::child($node, self::COMMON, 'arch')),
            'version' => $version?->getAttribute('ver'),
            'epoch' => $version?->getAttribute('epoch') ?: '0',
            'release' => $version?->getAttribute('rel'),
            'summary' => $text(self::child($node, self::COMMON, 'summary')),
            'description' => $text(self::child($node, self::COMMON, 'description')),
            'url' => $text(self::child($node, self::COMMON, 'url')),
            'time_file' => $time?->getAttribute('file'),
            'time_build' => $time?->getAttribute('build'),
            'rpm_license' => $text(self::child($format, self::RPM, 'license')),
            'rpm_vendor' => $text(self::child($format, self::RPM, 'vendor')),
            'rpm_group' => $text(self::child($format, self::RPM, 'group')),
            'rpm_buildhost' => $text(self::child($format, self::RPM, 'buildhost')),
            'rpm_sourcerpm' => $text(self::child($format, self::RPM, 'sourcerpm')),
            'rpm_header_start' => $range?->getAttribute('start'),
            'rpm_header_end' => $range?->getAttribute('end'),
            'rpm_packager' => $text(self::child($node, self::COMMON, 'packager')),
            'size_package' => $size?->getAttribute('package'),
            'size_installed' => $size?->getAttribute('installed'),
            'size_archive' => $size?->getAttribute('archive'),
            'location_href' => $location?->getAttribute('href'),
            'location_base' => $location?->getAttribute('xml:base') ?: null,
            'checksum_type' => $checksum?->getAttribute('type'),
        ];
    }

    private static function child(?\DOMElement $node, string $namespace, string $name): ?\DOMElement
    {
        foreach (self::children($node, $namespace, $name) as $child) {
            return $child;
        }

        return null;
    }

    /** @return list<\DOMElement> enfants directs */
    private static function children(?\DOMElement $node, string $namespace, string $name): array
    {
        $children = [];
        foreach (null === $node ? [] : $node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name && $child->namespaceURI === $namespace) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private static function attribute(\DOMElement $node, string $name): ?string
    {
        return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }
}
