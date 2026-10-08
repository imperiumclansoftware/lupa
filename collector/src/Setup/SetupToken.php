<?php

namespace App\Setup;

use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Code d'installation à usage unique : tant qu'aucun compte n'existe, l'assistant d'installation
 * exige ce code, lisible seulement par qui a accès au serveur (journaux du conteneur, console).
 * Sans lui, la première personne à joindre le serveur pourrait s'attribuer le compte administrateur.
 */
final readonly class SetupToken
{
    public function __construct(
        private UserRepository $users,
        #[Autowire('%env(SETUP_TOKEN_FILE)%')]
        private string $file,
    ) {
    }

    public function isRequired(): bool
    {
        return 0 === $this->users->count([]);
    }

    /** Code en cours (créé au premier appel). */
    public function code(): string
    {
        if (is_file($this->file) && '' !== $code = trim((string) file_get_contents($this->file))) {
            return $code;
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; ++$i) {
            $code .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }
        $code = implode('-', str_split($code, 4));

        $dir = \dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $old = umask(0o077);
        file_put_contents($this->file, $code);
        umask($old);

        return $code;
    }

    public function verify(string $code): bool
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return is_file($this->file) && hash_equals(str_replace('-', '', $this->code()), $normalized);
    }

    public function consume(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}
