<?php

namespace App\Import;

use App\Repository\ImportedBundleRepository;
use App\Repository\TrustedKeyRepository;
use Sas\Bundle\BlobStore;
use Sas\Bundle\BundleReader;
use Sas\Bundle\Manifest;
use Sas\Bundle\Signer;

/** Lit le manifeste d'un bundle (sans rien importer) et dit s'il peut être importé. */
final readonly class BundleInspector
{
    public function __construct(
        private TrustedKeyRepository $keys,
        private ImportedBundleRepository $bundles,
        private BlobStore $store,
    ) {
    }

    /**
     * @param list<string> $parts
     *
     * @return array{manifest: Manifest, reader: BundleReader, report: array<string, mixed>}
     */
    public function inspect(array $parts): array
    {
        $reader = new BundleReader($parts);
        $manifest = $reader->manifest();

        $key = $this->keys->findOneBy(['publicKey' => $manifest->collectorPublicKey]);
        $signatureValid = $key && $reader->verifySignature($key->getPublicKey());

        $last = $this->bundles->findOneBy(['collectorId' => $manifest->collectorId], ['sequence' => 'DESC']);
        $lastSequence = $last?->getSequence();
        $gap = !$manifest->full && null !== $lastSequence && $manifest->sequence > $lastSequence + 1
            ? range($lastSequence + 1, $manifest->sequence - 1) : [];
        if (!$manifest->full && null === $lastSequence && $manifest->sequence > 1) {
            $gap = range(1, $manifest->sequence - 1);
        }

        // Fichiers attendus d'un bundle précédent et absents du miroir
        $carried = array_flip(array_column($manifest->blobs, 'sha256'));
        $missing = [];
        foreach ($manifest->artifacts as $artifact) {
            foreach ($artifact['blobs'] as $sha) {
                if (!isset($carried[$sha]) && !$this->store->has($sha)) {
                    $missing[$artifact['ecosystem'].' '.$artifact['name'].' '.$artifact['version']] = true;
                }
            }
        }

        $summary = [];
        foreach ($manifest->artifacts as $artifact) {
            $summary[$artifact['ecosystem']] = ($summary[$artifact['ecosystem']] ?? 0) + 1;
        }

        $alreadyImported = null !== $this->bundles->findOneBy(['uuid' => $manifest->id]);

        return ['manifest' => $manifest, 'reader' => $reader, 'report' => [
            'id' => $manifest->id,
            'collector' => [
                'id' => $manifest->collectorId,
                'name' => $manifest->collectorName,
                'fingerprint' => Signer::isPublicKey($manifest->collectorPublicKey) ? Signer::fingerprint($manifest->collectorPublicKey) : null,
                'trusted' => null !== $key,
            ],
            'signatureValid' => $signatureValid,
            'sequence' => $manifest->sequence,
            'full' => $manifest->full,
            'createdAt' => $manifest->createdAt->format(\DATE_ATOM),
            'artifactCount' => \count($manifest->artifacts),
            'blobCount' => \count($manifest->blobs),
            'size' => $manifest->totalSize(),
            'summary' => (object) $summary,
            'blacklist' => null === $manifest->blacklist ? null : ['revision' => $manifest->blacklist['revision'], 'entries' => \count($manifest->blacklist['entries'])],
            'lastImportedSequence' => $lastSequence,
            'missingSequences' => $gap,
            'missingArtifacts' => \array_slice(array_keys($missing), 0, 50),
            'missingArtifactCount' => \count($missing),
            'alreadyImported' => $alreadyImported,
            'importable' => $signatureValid && [] === $missing,
        ]];
    }
}
