<?php

namespace App\Job;

use App\Entity\Job;
use App\Resolver\Resolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Résout une liste : calcule les dépendances et télécharge ce qui manque. */
final readonly class ResolveRunner implements JobRunner
{
    /** @param iterable<Resolver> $resolvers */
    public function __construct(
        #[AutowireIterator('app.resolver')]
        private iterable $resolvers,
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'resolve';
    }

    public function run(Job $job, JobContext $context): string
    {
        $list = $job->getList() ?? throw new \RuntimeException('La liste a été supprimée.');
        $resolver = null;
        foreach ($this->resolvers as $candidate) {
            if ($candidate->ecosystem() === $list->getEcosystem()) {
                $resolver = $candidate;
            }
        }
        if (!$resolver) {
            throw new \LogicException(sprintf('Aucun résolveur pour %s.', $list->getEcosystem()->value));
        }
        if ($errors = $resolver->validate($list)) {
            throw new \RuntimeException(implode("\n", $errors));
        }

        $context->log(sprintf('Liste « %s » (%s).', $list->getName(), $list->getEcosystem()->label()));
        $artifacts = [];
        foreach ($resolver->resolve($list, $context) as $artifact) {
            $artifacts[spl_object_id($artifact)] = $artifact;
        }
        $list->replaceArtifacts(array_values($artifacts));
        $this->em->flush();

        $pending = \count(array_filter($artifacts, static fn ($a) => null === $a->getExport()));
        $size = array_sum(array_map(static fn ($a) => $a->getSize(), $artifacts));

        return sprintf('%d paquet(s), %s ; %d à exporter', \count($artifacts), Bytes::format($size), $pending);
    }
}
