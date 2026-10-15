<?php

namespace App\Controller;

use App\Registry\Files;
use App\Registry\OpenPgp;
use App\Registry\SystemRepositories;
use Sas\Bundle\Distributions;
use Sas\Bundle\Ecosystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dépôts système en lecture seule.
 *   Debian : deb [signed-by=/etc/apt/keyrings/romulus.gpg] http://miroir/deb/debian-12 bookworm main
 *   RPM    : baseurl=http://miroir/rpm/almalinux-9/$basearch/ (ou /rpm/almalinux-9/romulus.repo)
 */
final class SystemRegistryController
{
    public function __construct(
        private readonly SystemRepositories $repositories,
        private readonly OpenPgp $pgp,
        private readonly Files $files,
    ) {
    }

    #[Route('/deb/romulus.{format}', methods: ['GET', 'HEAD'], requirements: ['format' => 'gpg|asc'])]
    public function key(string $format): Response
    {
        return 'gpg' === $format
            ? new Response($this->pgp->publicKey(), 200, ['Content-Type' => 'application/pgp-keys', 'Content-Disposition' => 'attachment; filename="romulus.gpg"'])
            : new Response($this->pgp->armoredPublicKey(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/deb/{distribution}/dists/{codename}/{path}', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+', 'codename' => '[a-z0-9-]+', 'path' => '.+'])]
    public function aptIndex(string $distribution, string $codename, string $path): Response
    {
        $dist = Distributions::find($distribution);
        $files = $dist && $dist['codename'] === $codename ? $this->repositories->apt($distribution) : null;
        if (null === $files || !isset($files[$path])) {
            return self::notFound();
        }

        return new Response($files[$path], 200, [
            'Content-Type' => str_ends_with($path, '.gz') ? 'application/gzip' : 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    #[Route('/deb/{distribution}/pool/{path}', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+', 'path' => '.+\.u?deb'])]
    public function aptPackage(string $distribution, string $path): Response
    {
        return $this->serve(Ecosystem::Deb, $distribution.'/pool/'.$path);
    }

    #[Route('/deb/{distribution}/romulus.sources', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+'])]
    public function aptSources(Request $request, string $distribution): Response
    {
        $dist = Distributions::find($distribution);
        $files = $dist ? $this->repositories->apt($distribution) : null;
        if (null === $files) {
            return self::notFound();
        }
        preg_match('/^Components: (.+)$/m', $files['Release'], $m);

        return new Response(sprintf("Types: deb\nURIs: %s/deb/%s\nSuites: %s\nComponents: %s\nSigned-By: /etc/apt/keyrings/romulus.gpg\n", $this->files->baseUrl($request), $distribution, $dist['codename'], $m[1] ?? 'main'), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/rpm/{distribution}/{arch}/repodata/{file}', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+', 'arch' => '[a-z0-9_]+', 'file' => '[a-z0-9.-]+'])]
    public function rpmIndex(string $distribution, string $arch, string $file): Response
    {
        $files = $this->repositories->rpm($distribution, $arch);
        if (null === $files || !isset($files[$file])) {
            return self::notFound();
        }

        return new Response($files[$file], 200, [
            'Content-Type' => str_ends_with($file, '.gz') ? 'application/gzip' : 'application/xml',
            'Cache-Control' => 'repomd.xml' === $file ? 'no-cache' : 'public, max-age=31536000, immutable',
        ]);
    }

    #[Route('/rpm/{distribution}/{arch}/Packages/{file}', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+', 'arch' => '[a-z0-9_]+', 'file' => '[^/]+\.rpm'])]
    public function rpmPackage(string $distribution, string $arch, string $file): Response
    {
        return $this->serve(Ecosystem::Rpm, $distribution.'/'.$arch.'/'.$file);
    }

    /** Fichier .repo prêt à copier dans /etc/yum.repos.d/ */
    #[Route('/rpm/{distribution}/romulus.repo', methods: ['GET', 'HEAD'], requirements: ['distribution' => '[a-z0-9-]+'])]
    public function rpmRepo(Request $request, string $distribution): Response
    {
        $dist = Distributions::find($distribution);
        if (!$dist || Ecosystem::Rpm !== $dist['ecosystem']) {
            return self::notFound();
        }

        return new Response(self::repoFile($this->files->baseUrl($request), $distribution, $dist), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string, mixed> $dist */
    public static function repoFile(string $base, string $distribution, array $dist): string
    {
        // module_hotfixes : sans métadonnées de modules, dnf (EL8) ne doit pas filtrer les paquets modulaires
        return sprintf("[romulus-%1\$s]\nname=Romulus - %2\$s\nbaseurl=%3\$s/rpm/%1\$s/\$basearch/\nenabled=1\ngpgcheck=1\ngpgkey=file://%4\$s\nrepo_gpgcheck=0\nmodule_hotfixes=1\n", $distribution, $dist['label'], $base, $dist['clientKey']);
    }

    private function serve(Ecosystem $ecosystem, string $filename): Response
    {
        $package = $this->repositories->package($ecosystem, $filename);
        if (null === $package) {
            return self::notFound();
        }
        if ($entry = $package['quarantine']) {
            return new Response(sprintf("%s %s est en quarantaine (liste noire)%s\n", $entry->getName(), $entry->getVersion(), '' !== $entry->getReason() ? ' : '.$entry->getReason() : ''), 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $blobs = json_decode((string) $package['blobs'], true) ?: [];

        return $this->files->serve((string) ($blobs[0] ?? ''), Ecosystem::Deb === $ecosystem ? 'application/vnd.debian.binary-package' : 'application/x-rpm');
    }

    private static function notFound(): Response
    {
        return new Response("Absent du miroir hors ligne.\n", 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
