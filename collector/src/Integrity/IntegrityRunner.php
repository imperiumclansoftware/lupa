<?php

namespace App\Integrity;

use App\Entity\Job;
use App\Entity\PackageList;
use App\Job\Bytes;
use App\Job\JobContext;
use App\Job\JobRunner;
use App\Job\ResolveRunner;
use App\Repository\ArtifactRepository;
use App\Repository\BlobRepository;
use App\Repository\PackageListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Vérification d'intégrité du stockage : les fichiers abîmés sont mis de côté, puis les listes qui contiennent
 * les paquets touchés sont mises à jour (le résolveur retélécharge ce qui manque, empreinte vérifiée). Le rapport
 * indique pour chaque paquet s'il a été corrigé ; sinon pourquoi.
 */
final readonly class IntegrityRunner implements JobRunner
{
    /** Au-delà, le rapport est tronqué (stockage entièrement perdu, par exemple) */
    public const int MAX_ITEMS = 1000;

    public function __construct(
        private IntegrityChecker $checker,
        private ResolveRunner $resolver,
        private ArtifactRepository $artifacts,
        private BlobRepository $blobs,
        private PackageListRepository $lists,
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'integrity';
    }

    public function run(Job $job, JobContext $context): string
    {
        $context->log('Vérification de l\'intégrité du stockage.');
        $scan = $this->checker->scan($context, 0, 60);
        $damaged = $scan['damaged'];
        $context->log(sprintf('%d fichier(s) vérifié(s) (%s) pour %d paquet(s) : %d paquet(s) non intègre(s).', $scan['files'], Bytes::format($scan['bytes']), $scan['artifacts'], \count($damaged)));

        // Fichiers abîmés mis de côté : le résolveur les verra comme absents et les retéléchargera
        $isolated = [];
        foreach ($damaged as $entry) {
            foreach ($entry['files'] as $file) {
                if (IntegrityChecker::MISSING !== $file['problem'] && !isset($isolated[$file['sha256']])) {
                    $isolated[$file['sha256']] = $this->checker->isolate($file['sha256']);
                    $context->log(sprintf('Fichier %s mis de côté : %s.', $file['sha256'], $isolated[$file['sha256']]));
                }
            }
        }

        // Listes à mettre à jour, et listes de chaque paquet touché (avant mise à jour)
        $lists = [];
        $listsOf = [];
        foreach (array_keys($damaged) as $id) {
            foreach ($this->lists->containing(Uuid::fromString($id)) as $list) {
                $lists[$list->getId()->toRfc4122()] = $list;
                $listsOf[$id][] = ['id' => $list->getId()->toRfc4122(), 'name' => $list->getName()];
            }
        }

        $errors = $this->repair(array_values($lists), $context);

        // Contrôle après retéléchargement
        $this->em->clear();
        $items = [];
        $repaired = 0;
        foreach ($damaged as $id => $entry) {
            $message = $this->stillDamaged($id, $listsOf[$id] ?? [], $errors);
            null === $message ? ++$repaired : $context->log(sprintf('Non corrigé : %s : %s', IntegrityChecker::label($entry['artifact']), $message));
            $items[] = $entry['artifact'] + [
                'files' => $entry['files'],
                'lists' => $listsOf[$id] ?? [],
                'status' => null === $message ? 'repaired' : 'failed',
                'message' => $message,
            ];
        }
        // Erreurs d'abord (« failed » < « repaired »)
        usort($items, static fn (array $a, array $b) => [$a['status'], $a['ecosystem'], $a['name'], $a['version']] <=> [$b['status'], $b['ecosystem'], $b['name'], $b['version']]);

        $failed = \count($damaged) - $repaired;
        $context->saveReport([
            'artifacts' => $scan['artifacts'],
            'files' => $scan['files'],
            'bytes' => $scan['bytes'],
            'damaged' => \count($damaged),
            'repaired' => $repaired,
            'failed' => $failed,
            'truncated' => \count($items) > self::MAX_ITEMS,
            'items' => \array_slice($items, 0, self::MAX_ITEMS),
        ]);
        $context->report('Vérification terminée', 100);

        return [] === $damaged
            ? sprintf('%d fichier(s) vérifié(s) (%s) : tous intègres', $scan['files'], Bytes::format($scan['bytes']))
            : sprintf('%d fichier(s) vérifié(s) : %d paquet(s) non intègre(s), %d corrigé(s), %d en erreur', $scan['files'], \count($damaged), $repaired, $failed);
    }

    /**
     * Met à jour chaque liste concernée ; une liste en échec n'empêche pas les suivantes.
     *
     * @param list<PackageList> $lists
     *
     * @return array<string, string> erreur par identifiant de liste
     */
    private function repair(array $lists, JobContext $context): array
    {
        $errors = [];
        foreach ($lists as $index => $list) {
            $id = $list->getId()->toRfc4122();
            $context->report(sprintf('Retéléchargement : liste « %s » (%d/%d)', $list->getName(), $index + 1, \count($lists)), 60 + intdiv(40 * $index, \count($lists)));
            if (!$this->em->isOpen()) {
                $errors[$id] = 'mise à jour interrompue après l\'échec d\'une liste précédente';
                continue;
            }
            try {
                $this->resolver->resolveList($list, $context);
                $context->log(sprintf('Liste « %s » mise à jour.', $list->getName()));
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                $context->log(sprintf('ERREUR mise à jour de « %s » : %s', $list->getName(), $e->getMessage()));
            }
        }

        return $errors;
    }

    /**
     * @param list<array{id: string, name: string}> $lists  listes du paquet avant la mise à jour
     * @param array<string, string>                 $errors
     *
     * @return string|null null si tous les fichiers du paquet sont de nouveau intègres, sinon la raison
     */
    private function stillDamaged(string $id, array $lists, array $errors): ?string
    {
        $artifact = $this->artifacts->find(Uuid::fromString($id));
        if (null === $artifact) {
            return 'paquet supprimé pendant la vérification';
        }
        $intact = true;
        foreach ($artifact->getBlobs() as $sha) {
            if (null !== $this->checker->verify($sha, $this->blobs->find($sha)?->getSize())) {
                $intact = false;
            }
        }
        if ($intact) {
            return null;
        }
        if ([] === $lists) {
            return 'aucune liste ne contient ce paquet : Rémus ne sait pas où le retélécharger';
        }
        foreach ($lists as $list) {
            if (isset($errors[$list['id']])) {
                return sprintf('mise à jour de la liste « %s » en échec : %s', $list['name'], $errors[$list['id']]);
            }
        }
        if ([] === $this->lists->containing($artifact->getId())) {
            return 'la mise à jour des listes a retenu une autre version : ce paquet n\'a pas été retéléchargé';
        }

        return 'fichier toujours absent ou incorrect après un nouveau téléchargement';
    }
}
