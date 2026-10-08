<?php

namespace App\Resolver;

use App\Entity\Artifact;
use App\Entity\PackageList;
use App\Job\JobContext;
use Sas\Bundle\Ecosystem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Résout une liste (dépendances comprises) et télécharge ce qui manque dans le stockage. */
#[AutoconfigureTag('app.resolver')]
interface Resolver
{
    public function ecosystem(): Ecosystem;

    /**
     * Vérifie les spécifications et options saisies, sans rien télécharger.
     *
     * @return list<string> erreurs lisibles
     */
    public function validate(PackageList $list): array;

    /** @return list<Artifact> contenu complet de la liste */
    public function resolve(PackageList $list, JobContext $context): array;
}
