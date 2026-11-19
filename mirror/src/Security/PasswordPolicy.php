<?php

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/** Politique des mots de passe : 12 caractères minimum et robustesse suffisante (pas de vérification des fuites hors ligne, ADR 0003). */
final class PasswordPolicy
{
    /** @return list<Constraint> */
    public static function constraints(): array
    {
        return [
            new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
            new Assert\Length(min: 12, max: 4096, minMessage: 'Mot de passe : 12 caractères minimum.'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Mot de passe trop prévisible : allongez-le ou mélangez mots, chiffres et symboles.'),
        ];
    }
}
