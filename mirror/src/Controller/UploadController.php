<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Dto\UploadInput;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Envoi d'un bundle depuis le navigateur, par morceaux (les bundles font souvent plusieurs Go) :
 *   POST /api/uploads {filename, size}  ->  PUT /api/uploads/{id}?offset=…  (corps brut)  ->  POST /api/uploads/{id}/complete
 * Un envoi interrompu reprend à l'offset déjà reçu.
 */
#[Route('/api/uploads')]
final class UploadController
{

    public function __construct(private readonly string $uploadDir)
    {
    }

    #[Route('', methods: ['POST'])]
    public function start(#[MapRequestPayload] UploadInput $input): JsonResponse
    {
        [$filename, $size] = [$input->filename, $input->size];
        $this->ensureDir();
        $free = @disk_free_space($this->uploadDir);
        if (false !== $free && $free < $size) {
            throw new BadRequestHttpException('Espace disque insuffisant sur le miroir.');
        }

        // Reprise : même nom et même taille qu'un envoi inachevé
        $id = substr(hash('sha256', $filename.'|'.$size), 0, 32);
        file_put_contents($this->partial($id).'.json', json_encode(['filename' => $filename, 'size' => $size]));
        if (!is_file($this->partial($id))) {
            touch($this->partial($id));
        }

        return new JsonResponse(['id' => $id, 'received' => filesize($this->partial($id))], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '[a-f0-9]{32}'])]
    public function chunk(Request $request, string $id): JsonResponse
    {
        $meta = $this->meta($id);
        $offset = $request->query->getInt('offset', -1);
        clearstatcache(true, $this->partial($id));
        $received = filesize($this->partial($id));
        if ($offset !== $received) {
            return new JsonResponse(['error' => 'Position incorrecte.', 'received' => $received], Response::HTTP_CONFLICT);
        }

        $in = $request->getContent(true);
        $out = fopen($this->partial($id), 'a');
        $written = stream_copy_to_stream($in, $out);
        fclose($out);
        if ($received + $written > $meta['size']) {
            $handle = fopen($this->partial($id), 'r+');
            ftruncate($handle, $received);
            fclose($handle);
            throw new BadRequestHttpException('Taille dépassée.');
        }

        return new JsonResponse(['received' => $received + $written]);
    }

    #[Route('/{id}/complete', methods: ['POST'], requirements: ['id' => '[a-f0-9]{32}'])]
    public function complete(string $id): JsonResponse
    {
        $meta = $this->meta($id);
        clearstatcache(true, $this->partial($id));
        if (filesize($this->partial($id)) !== $meta['size']) {
            throw new BadRequestHttpException('Envoi incomplet.');
        }
        rename($this->partial($id), $this->uploadDir.'/'.$meta['filename']);
        unlink($this->partial($id).'.json');

        return new JsonResponse(['filename' => $meta['filename']]);
    }

    /** @return array{filename: string, size: int} */
    private function meta(string $id): array
    {
        $file = $this->partial($id).'.json';
        if (!is_file($file)) {
            throw new NotFoundHttpException('Envoi inconnu.');
        }

        return json_decode((string) file_get_contents($file), true);
    }

    private function partial(string $id): string
    {
        return $this->uploadDir.'/.partial/'.$id;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->uploadDir.'/.partial')) {
            mkdir($this->uploadDir.'/.partial', 0o770, true);
        }
    }
}
