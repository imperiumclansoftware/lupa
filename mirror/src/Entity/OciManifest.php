<?php

namespace App\Entity;

use App\Repository\OciManifestRepository;
use Doctrine\ORM\Mapping as ORM;

/** Type de contenu de chaque manifeste Docker, pour les accès par empreinte (image@sha256:…). */
#[ORM\Entity(repositoryClass: OciManifestRepository::class)]
class OciManifest
{
    #[ORM\Id, ORM\Column(length: 71)]
    private string $digest;

    #[ORM\Column(length: 120)]
    private string $mediaType;

    public function __construct(string $digest, string $mediaType)
    {
        $this->digest = $digest;
        $this->mediaType = $mediaType;
    }

    public function getDigest(): string
    {
        return $this->digest;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function sha256(): string
    {
        return substr($this->digest, 7);
    }
}
