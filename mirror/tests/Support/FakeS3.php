<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Serveur S3 en mémoire (adressage par chemin : http://s3.test/<bucket>/<clé>) pour MockHttpClient : PUT
 * (empreinte x-amz-checksum-sha256 vérifiée, copie), GET (plages d'octets), HEAD, DELETE, ListObjectsV2.
 */
final class FakeS3
{
    /** @var array<string, string> clé complète (bucket/clé) => contenu */
    public array $objects = [];

    /** @var list<string> clés dont l'envoi est refusé (panne simulée) */
    public array $failing = [];

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this->handle(...), 'http://s3.test');
    }

    /** @param array<string, mixed> $options */
    private function handle(string $method, string $url, array $options): MockResponse
    {
        $parts = parse_url($url);
        $path = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        parse_str((string) ($parts['query'] ?? ''), $query);
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = array_map('trim', explode(':', (string) $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }

        if ('GET' === $method && isset($query['list-type'])) {
            $prefix = $path.'/'.($query['prefix'] ?? '');
            $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>'.$path.'</Name><IsTruncated>false</IsTruncated>';
            foreach ($this->objects as $key => $content) {
                if (str_starts_with($key, $prefix)) {
                    $xml .= '<Contents><Key>'.htmlspecialchars(substr($key, \strlen($path) + 1)).'</Key><Size>'.\strlen($content).'</Size></Contents>';
                }
            }

            return new MockResponse($xml.'</ListBucketResult>', ['response_headers' => ['Content-Type' => 'application/xml']]);
        }

        return match ($method) {
            'PUT' => $this->put($path, $headers, $options),
            'GET', 'HEAD' => $this->get($method, $path, $headers),
            'DELETE' => (function () use ($path): MockResponse {
                unset($this->objects[$path]);

                return new MockResponse('', ['http_code' => 204]);
            })(),
            default => new MockResponse('', ['http_code' => 405]),
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    private function put(string $path, array $headers, array $options): MockResponse
    {
        if (\in_array($path, $this->failing, true)) {
            return self::error(500, 'InternalError', 'Panne simulée');
        }
        if (isset($headers['x-amz-copy-source'])) {
            $source = rawurldecode(ltrim($headers['x-amz-copy-source'], '/'));
            if (!isset($this->objects[$source])) {
                return self::error(404, 'NoSuchKey', 'Absent');
            }
            $this->objects[$path] = $this->objects[$source];

            return new MockResponse('<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>');
        }
        $body = $options['body'] ?? '';
        if (\is_callable($body)) {
            $data = '';
            while ('' !== $chunk = (string) $body(16384)) {
                $data .= $chunk;
            }
            $body = $data;
        }
        $body = (string) $body;
        if (isset($headers['x-amz-checksum-sha256']) && $headers['x-amz-checksum-sha256'] !== base64_encode(hash('sha256', $body, true))) {
            return self::error(400, 'BadDigest', 'The SHA256 you specified did not match the calculated checksum.');
        }
        $this->objects[$path] = $body;

        return new MockResponse('', ['response_headers' => ['ETag' => '"'.md5($body).'"']]);
    }

    /** @param array<string, string> $headers */
    private function get(string $method, string $path, array $headers): MockResponse
    {
        if (!isset($this->objects[$path])) {
            return 'HEAD' === $method ? new MockResponse('', ['http_code' => 404]) : self::error(404, 'NoSuchKey', 'Absent');
        }
        $content = $this->objects[$path];
        if (isset($headers['range']) && preg_match('/^bytes=(\d+)-(\d*)$/', $headers['range'], $m)) {
            $end = '' === $m[2] ? \strlen($content) - 1 : (int) $m[2];
            $part = substr($content, (int) $m[1], $end - (int) $m[1] + 1);

            return new MockResponse($part, ['http_code' => 206, 'response_headers' => ['Content-Length' => (string) \strlen($part)]]);
        }

        return new MockResponse('HEAD' === $method ? '' : $content, ['response_headers' => ['Content-Length' => (string) \strlen($content)]]);
    }

    private static function error(int $status, string $code, string $message): MockResponse
    {
        return new MockResponse(sprintf('<?xml version="1.0"?><Error><Code>%s</Code><Message>%s</Message></Error>', $code, $message), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/xml']]);
    }
}
