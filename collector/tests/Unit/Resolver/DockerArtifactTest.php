<?php

namespace App\Tests\Unit\Resolver;

use App\Resolver\DockerResolver;
use PHPUnit\Framework\TestCase;

/** Artefacts OCI (bases Trivy…) distingués des images : pas de plateforme à vérifier. */
final class DockerArtifactTest extends TestCase
{
    public function testTrivyDatabaseIsAnArtifact(): void
    {
        // Manifeste réel de ghcr.io/aquasecurity/trivy-db:2 (digests abrégés)
        self::assertTrue(DockerResolver::isArtifact([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'artifactType' => 'application/vnd.aquasec.trivy.config.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.empty.v1+json', 'digest' => 'sha256:44136fa3', 'size' => 2],
            'layers' => [['mediaType' => 'application/vnd.aquasec.trivy.db.layer.v1.tar+gzip', 'digest' => 'sha256:1a2b', 'size' => 145_000_000]],
        ]));
        // Artefact sans artifactType (ancienne forme) : configuration propre à l'outil
        self::assertTrue(DockerResolver::isArtifact(['config' => ['mediaType' => 'application/vnd.cncf.helm.config.v1+json']]));
    }

    public function testImagesAreNotArtifacts(): void
    {
        self::assertFalse(DockerResolver::isArtifact(['config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json']]));
        self::assertFalse(DockerResolver::isArtifact(['config' => ['mediaType' => 'application/vnd.docker.container.image.v1+json']]));
    }
}
