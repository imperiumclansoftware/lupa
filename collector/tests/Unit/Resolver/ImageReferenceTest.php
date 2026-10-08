<?php

namespace App\Tests\Unit\Resolver;

use App\Resolver\Docker\ImageReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageReferenceTest extends TestCase
{
    private const string DIGEST = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @return iterable<string, array{string, string, string, string, string, string}> entrée, registre, dépôt, référence, nom servi, forme complète */
    public static function references(): iterable
    {
        yield 'image officielle' => ['nginx', 'docker.io', 'library/nginx', 'latest', 'library/nginx', 'docker.io/library/nginx:latest'];
        yield 'étiquette' => ['nginx:1.27-alpine', 'docker.io', 'library/nginx', '1.27-alpine', 'library/nginx', 'docker.io/library/nginx:1.27-alpine'];
        yield 'utilisateur Docker Hub' => ['user/img:tag', 'docker.io', 'user/img', 'tag', 'user/img', 'docker.io/user/img:tag'];
        yield 'docker.io explicite' => ['docker.io/redis', 'docker.io', 'library/redis', 'latest', 'library/redis', 'docker.io/library/redis:latest'];
        yield 'autre registre' => ['ghcr.io/o/a:1.2', 'ghcr.io', 'o/a', '1.2', 'ghcr.io/o/a', 'ghcr.io/o/a:1.2'];
        yield 'registre avec port' => ['localhost:5000/x', 'localhost:5000', 'x', 'latest', 'localhost:5000/x', 'localhost:5000/x:latest'];
        yield 'registre avec port et étiquette' => ['localhost:5000/x:2', 'localhost:5000', 'x', '2', 'localhost:5000/x', 'localhost:5000/x:2'];
        yield 'localhost sans port' => ['localhost/x', 'localhost', 'x', 'latest', 'localhost/x', 'localhost/x:latest'];
        yield 'empreinte' => ['alpine@'.self::DIGEST, 'docker.io', 'library/alpine', self::DIGEST, 'library/alpine', 'docker.io/library/alpine@'.self::DIGEST];
        yield 'étiquette ignorée devant une empreinte' => ['alpine:3.20@'.self::DIGEST, 'docker.io', 'library/alpine', self::DIGEST, 'library/alpine', 'docker.io/library/alpine@'.self::DIGEST];
        yield 'espaces' => ['  quay.io/org/app:v1  ', 'quay.io', 'org/app', 'v1', 'quay.io/org/app', 'quay.io/org/app:v1'];
    }

    #[DataProvider('references')]
    public function testParse(string $input, string $registry, string $repository, string $reference, string $mirrorName, string $full): void
    {
        $image = ImageReference::parse($input);

        self::assertSame($registry, $image->registry);
        self::assertSame($repository, $image->repository);
        self::assertSame($reference, $image->reference);
        self::assertSame($mirrorName, $image->mirrorName());
        self::assertSame($full, (string) $image);
        self::assertSame(str_starts_with($reference, 'sha256:'), $image->isDigest());
    }

    public function testEndpoint(): void
    {
        self::assertSame('https://registry-1.docker.io', ImageReference::parse('nginx')->endpoint());
        self::assertSame('https://ghcr.io', ImageReference::parse('ghcr.io/o/a')->endpoint());
        self::assertSame('https://localhost:5000', ImageReference::parse('localhost:5000/x')->endpoint());
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'vide' => [''];
        yield 'majuscules' => ['Nginx'];
        yield 'espace' => ['mon image'];
        yield 'double barre' => ['foo//bar'];
        yield 'deux-points final' => ['nginx:'];
        yield 'séparateur en tête' => ['-nginx'];
        yield 'barre finale' => ['ghcr.io/o/'];
    }

    #[DataProvider('invalid')]
    public function testInvalidNamesThrow(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ImageReference::parse($input);
    }
}
