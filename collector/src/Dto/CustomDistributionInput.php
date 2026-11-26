<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Distribution personnalisée saisie dans les Réglages (listes en texte : une valeur par mot ou par ligne). */
final readonly class CustomDistributionInput
{
    public function __construct(
        #[Assert\Choice(choices: ['deb', 'rpm'], message: 'Type de distribution inconnu.')]
        public string $ecosystem = 'deb',
        #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]{1,31}$/', message: 'Identifiant invalide : minuscules, chiffres et tirets (ex. debian-12-interne).')]
        public string $slug = '',
        #[Assert\NotBlank(message: 'Donnez un nom à la distribution.'), Assert\Length(max: 120)]
        public string $label = '',
        #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', message: 'Version invalide (ex. 12, 9.4).')]
        public string $release = '',
        /** Debian : suite servie par Romulus (minuscules, chiffres, tirets) */
        #[Assert\Length(max: 60)]
        public ?string $codename = null,
        public bool $eol = false,
        /** Architectures séparées par des espaces (amd64 arm64, x86_64 aarch64) */
        #[Assert\Length(max: 200)]
        public string $architectures = '',
        /** Debian : composants séparés par des espaces */
        #[Assert\Length(max: 500)]
        public ?string $sections = null,
        /** Debian : « adresse suite » par ligne ; RPM : « nom adresse » par ligne */
        #[Assert\Length(max: 5000)]
        public string $sources = '',
        /** Vide à la modification = clé inchangée */
        #[Assert\Length(max: 100000)]
        public ?string $signingKey = null,
        /** Test d'une distribution déjà enregistrée : réutilise sa clé si aucune n'est saisie */
        #[Assert\Uuid(versions: [Assert\Uuid::V7_MONOTONIC])]
        public ?string $id = null,
    ) {
    }
}
