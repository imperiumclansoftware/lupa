<?php

namespace App\Security;

/**
 * Niveaux de droits, du plus large au plus restreint (hiérarchie dans security.yaml, ADR 0010).
 *
 * Lecteur : consulte tout. Opérateur : en plus, fait le travail courant (listes, analyses, exports
 * sur Rémus ; imports sur Romulus). Administrateur : en plus, comptes, réglages, liste noire, dépôts
 * sources et clés de confiance.
 */
enum Role: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Reader = 'reader';

    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Operator => 'Opérateur',
            self::Reader => 'Lecteur',
        };
    }
}
