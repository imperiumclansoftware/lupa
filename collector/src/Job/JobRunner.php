<?php

namespace App\Job;

use App\Entity\Job;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Exécute un type de tâche (« resolve », « export »). */
#[AutoconfigureTag('app.job_runner')]
interface JobRunner
{
    public function type(): string;

    /** @return string message de fin affiché dans l'interface */
    public function run(Job $job, JobContext $context): string;
}
