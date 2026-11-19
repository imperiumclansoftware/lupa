<?php

namespace App\Security;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Masque les données sensibles dans tous les journaux : champs nommés mot de passe, secret, jeton,
 * cookie, Authorization… et identifiants inclus dans une URL (https://user:pass@hôte) ou un en-tête.
 */
#[AsMonologProcessor]
final class RedactProcessor
{
    private const string SENSITIVE_KEY = '/pass(word|wd)?|secret|token|authorization|cookie|api[_-]?key|private|credential/i';
    private const string MASK = '***';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::text($record->message),
            context: self::walk($record->context),
            extra: self::walk($record->extra),
        );
    }

    public static function text(string $text): string
    {
        return (string) preg_replace(
            ['#(//)[^/\s:@]+:[^/\s@]+@#', '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '/((?:password|token|secret)["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i'],
            ['$1'.self::MASK.'@', '$1 '.self::MASK, '$1'.self::MASK],
            $text,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function walk(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = self::MASK;
            } elseif (\is_string($value)) {
                $data[$key] = self::text($value);
            } elseif (\is_array($value) && $depth < 5) {
                $data[$key] = self::walk($value, $depth + 1);
            }
        }

        return $data;
    }
}
