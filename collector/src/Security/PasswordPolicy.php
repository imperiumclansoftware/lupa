<?php

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/** Politique des mots de passe : 12 caractères minimum, robustesse suffisante, absent des fuites connues. */
final class PasswordPolicy
{
    /** @return list<Constraint> */
    public static function constraints(): array
    {
        return [
            new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
            new Assert\Length(min: 12, max: 4096, minMessage: 'Mot de passe : 12 caractères minimum.'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Mot de passe trop prévisible : allongez-le ou mélangez mots, chiffres et symboles.'),
            // Liste des mots de passe divulgués (Have I Been Pwned, k-anonymat) : Rémus a Internet (ADR 0003)
            new Assert\NotCompromisedPassword(message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre.', skipOnError: true),
        ];
    }
}
