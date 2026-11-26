<?php

namespace App\Integrity;

use App\Job\Bytes;
use App\Job\JobContext;
use Doctrine\DBAL\Connection;
use Sas\Bundle\BlobStore;
use Sas\Bundle\Ecosystem;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle du stockage par empreinte : chaque fichier référencé par un paquet doit exister, avoir la taille
 * enregistrée et le sha256 sous lequel il est rangé. Un fichier abîmé est mis de côté dans « corrupt/ » :
 * le prochain téléchargement le remplace, et il n'est jamais exporté.
 */
final readonly class IntegrityChecker
{
    public const string MISSING = 'missing';
    public const string SIZE = 'size';
    public const string HASH = 'hash';

    public function __construct(
        private Connection $db,
        private BlobStore $store,
    ) {
    }

    /**
     * Vérifie tous les fichiers des paquets, en rapportant la progression entre $from et $to %.
     *
     * @return array{artifacts: int, files: int, bytes: int, damaged: array<string, array{artifact: array{id: string, ecosystem: string, name: string, version: string, filename: string|null}, files: list<array{sha256: string, problem: string}>}>}
     */
    public function scan(JobContext $context, int $from = 0, int $to = 100): array
    {
        $sizes = [];
        foreach ($this->db->iterateAssociative('SELECT sha256, size FROM blob') as $row) {
            /** @var array{sha256: string, size: int|string} $row */
            $sizes[$row['sha256']] = (int) $row['size'];
        }

        // Fichier → paquets qui l'utilisent (les couches Docker sont partagées entre images)
        $artifacts = [];
        $users = [];
        foreach ($this->db->iterateAssociative('SELECT id, ecosystem, name, version, filename, blobs FROM artifact') as $row) {
            /** @var array{id: string, ecosystem: string, name: string, version: string, filename: string|null, blobs: string} $row */
            $id = Uuid::fromBinary($row['id'])->toRfc4122();
            $artifacts[$id] = [
                'id' => $id,
                'ecosystem' => Ecosystem::from($row['ecosystem'])->value,
                'name' => $row['name'],
                'version' => $row['version'],
                'filename' => $row['filename'],
            ];
            /** @var list<string> $blobs */
            $blobs = json_decode($row['blobs'], true, 512, \JSON_THROW_ON_ERROR);
            foreach ($blobs as $sha) {
                $users[$sha][] = $id;
            }
        }

        $total = max(1, array_sum(array_map(static fn (string $sha) => $sizes[$sha] ?? 0, array_keys($users))));
        $done = 0;
        $index = 0;
        $damaged = [];
        foreach ($users as $sha => $ids) {
            ++$index;
            $context->report(sprintf('Vérification des fichiers (%d/%d, %s)', $index, \count($users), Bytes::format($done)), $from + intdiv(($to - $from) * $done, $total));
            if (null !== $problem = $this->verify($sha, $sizes[$sha] ?? null)) {
                $context->log(sprintf('Fichier %s : %s (%s).', $sha, self::describe($problem), implode(', ', array_map(static fn (string $id) => self::label($artifacts[$id]), $ids))));
                foreach ($ids as $id) {
                    $damaged[$id]['artifact'] = $artifacts[$id];
                    $damaged[$id]['files'][] = ['sha256' => $sha, 'problem' => $problem];
                }
            }
            $done += $sizes[$sha] ?? 0;
        }

        return ['artifacts' => \count($artifacts), 'files' => \count($users), 'bytes' => $done, 'damaged' => $damaged];
    }

    /** @return string|null null si le fichier est intact, sinon MISSING, SIZE ou HASH */
    public function verify(string $sha256, ?int $expectedSize): ?string
    {
        if (!$this->store->has($sha256)) {
            return self::MISSING;
        }
        if (null !== $expectedSize && $this->store->size($sha256) !== $expectedSize) {
            return self::SIZE;
        }

        return $this->store->withFile($sha256, static fn (string $path) => hash_equals($sha256, (string) hash_file('sha256', $path))) ? null : self::HASH;
    }

    /** Met de côté un fichier abîmé (conservé pour analyse) ; le stockage le considère alors comme absent. */
    public function isolate(string $sha256): ?string
    {
        return $this->store->isolate($sha256);
    }

    public static function describe(string $problem): string
    {
        return match ($problem) {
            self::MISSING => 'absent du stockage',
            self::SIZE => 'taille incorrecte',
            self::HASH => 'contenu modifié (empreinte sha256 incorrecte)',
            default => $problem,
        };
    }

    /** @param array{ecosystem: string, name: string, version: string, filename: string|null} $artifact */
    public static function label(array $artifact): string
    {
        return sprintf('%s %s %s%s', $artifact['ecosystem'], $artifact['name'], $artifact['version'], null !== $artifact['filename'] ? ' ('.$artifact['filename'].')' : '');
    }
}
