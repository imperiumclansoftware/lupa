<?php

namespace App\Setup;

use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Code d'installation à usage unique : tant qu'aucun compte n'existe, l'assistant d'installation
 * exige ce code, lisible seulement par qui a accès au serveur (journaux du conteneur, console).
 * Sans lui, la première personne à joindre le serveur pourrait s'attribuer le compte administrateur.
 *
 * Seule son empreinte SHA-256 est conservée : le code n'est visible qu'au moment où il est créé
 * (au démarrage du conteneur ou par app:setup:code, qui en crée un nouveau).
 */
final readonly class SetupToken
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

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

    /** Nouveau code (60 bits d'aléa) ; le précédent devient invalide. */
    public function issue(): string
    {
        $code = '';
        for ($i = 0; $i < 12; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }
        $dir = \dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $old = umask(0o077);
        file_put_contents($this->file, 'sha256:'.hash('sha256', $code));
        umask($old);

        return implode('-', str_split($code, 4));
    }

    public function verify(string $code): bool
    {
        if (!is_file($this->file)) {
            return false;
        }
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $stored = trim((string) file_get_contents($this->file));
        // Fichier d'une version précédente : code en clair
        $expected = str_starts_with($stored, 'sha256:') ? $stored : 'sha256:'.hash('sha256', strtoupper(str_replace('-', '', $stored)));

        return hash_equals($expected, 'sha256:'.hash('sha256', $normalized));
    }

    public function consume(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }
}
