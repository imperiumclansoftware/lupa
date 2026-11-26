<?php

namespace App\Storage;

use Sas\Bundle\BlobStore;
use Sas\Bundle\StorageSettings;

/** BlobStore dont le moteur suit la configuration (disque, ou bucket S3 une fois la migration faite). */
final readonly class BlobStoreFactory
{
    public function __construct(private StorageSettings $settings)
    {
    }

    public function create(string $root): BlobStore
    {
        return new BlobStore($root, $this->settings->backend(...));
    }
}
