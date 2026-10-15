<?php

namespace App\Source;

use App\Entity\Source;
use Sas\Bundle\Ecosystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Vérifie qu'un dépôt répond et accepte les identifiants, avant de s'en servir. */
final readonly class SourceTester
{
    public function __construct(
        private HttpClientInterface $http,
        private Sources $sources,
    ) {
    }

    /** @return array{ok: bool, message: string} */
    public function test(Source $source): array
    {
        try {
            return match ($source->getEcosystem()) {
                Ecosystem::Docker => $this->docker($source),
                default => $this->http($source),
            };
        } catch (ExceptionInterface $e) {
            return ['ok' => false, 'message' => 'Dépôt injoignable : '.$e->getMessage()];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function http(Source $source): array
    {
        // npm : /-/ping ne vérifie pas les identifiants, /-/whoami si (npm whoami)
        if (Ecosystem::Npm === $source->getEcosystem() && Source::AUTH_NONE !== $source->getAuthType()) {
            $response = $this->http->request('GET', $source->getUrl().'/-/whoami', $this->sources->authOptions($source) + ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            if (\in_array($status, [401, 403], true)) {
                return ['ok' => false, 'message' => sprintf('HTTP %d : identifiants refusés par le registre.', $status)];
            }
            if (200 === $status) {
                $user = json_decode($response->getContent(false), true)['username'] ?? null;
                if (!\is_string($user) || '' === $user) {
                    return ['ok' => false, 'message' => 'Le registre répond mais ne reconnaît pas les identifiants (session anonyme).'];
                }

                return ['ok' => true, 'message' => 'Connexion réussie, identifiants acceptés (utilisateur '.$user.').'];
            }
        }
        [$url, $expect] = match ($source->getEcosystem()) {
            Ecosystem::Npm => [$source->getUrl().'/-/ping', 'registre npm'],
            Ecosystem::Pypi => [$source->getUrl().'/', 'index Python (simple)'],
            default => [$source->getUrl().'/packages.json', 'dépôt Composer'],
        };
        $response = $this->http->request('GET', $url, $this->sources->authOptions($source) + ['timeout' => 15, 'headers' => ['Accept' => 'application/json, text/html;q=0.8']]);
        $status = $response->getStatusCode();
        if (\in_array($status, [401, 403], true)) {
            return ['ok' => false, 'message' => sprintf('HTTP %d : identifiants absents ou refusés par le dépôt.', $status)];
        }
        // Certains registres npm (Nexus, GitLab) n'ont pas /-/ping : la racine suffit alors
        if (404 === $status && Ecosystem::Npm === $source->getEcosystem()) {
            $status = $this->http->request('GET', $source->getUrl().'/', $this->sources->authOptions($source) + ['timeout' => 15])->getStatusCode();
            if (\in_array($status, [401, 403], true)) {
                return ['ok' => false, 'message' => sprintf('HTTP %d : identifiants absents ou refusés par le dépôt.', $status)];
            }
            if ($status < 500) {
                return ['ok' => true, 'message' => sprintf('Le dépôt répond (HTTP %d).', $status)];
            }
        }
        if (200 !== $status) {
            return ['ok' => false, 'message' => sprintf('HTTP %d pour %s : est-ce bien l\'adresse d\'un %s ?', $status, $url, $expect)];
        }
        if (Ecosystem::Composer === $source->getEcosystem()) {
            $data = json_decode($response->getContent(), true);
            if (!\is_array($data) || !array_intersect_key($data, array_flip(['packages', 'metadata-url', 'providers-url', 'provider-includes', 'includes', 'available-packages']))) {
                return ['ok' => false, 'message' => 'packages.json ne ressemble pas à celui d\'un dépôt Composer.'];
            }
        }

        return ['ok' => true, 'message' => sprintf('Connexion réussie : %s joignable%s.', $expect, Source::AUTH_NONE !== $source->getAuthType() ? ', identifiants acceptés' : '')];
    }

    /** @return array{ok: bool, message: string} */
    private function docker(Source $source): array
    {
        $response = $this->http->request('GET', $source->getUrl().'/v2/', ['timeout' => 15]);
        $status = $response->getStatusCode();
        if (200 === $status) {
            return ['ok' => true, 'message' => 'Registre joignable (accès anonyme).'];
        }
        if (401 !== $status) {
            return ['ok' => false, 'message' => sprintf('HTTP %d pour /v2/ : est-ce bien un registre Docker ?', $status)];
        }
        $challenge = $response->getHeaders(false)['www-authenticate'][0] ?? '';
        $auth = $this->sources->authOptions($source);
        if (str_starts_with(strtolower($challenge), 'basic')) {
            if (!$auth) {
                return ['ok' => false, 'message' => 'Le registre demande un identifiant et un mot de passe.'];
            }
            $status = $this->http->request('GET', $source->getUrl().'/v2/', $auth + ['timeout' => 15])->getStatusCode();

            return 200 === $status ? ['ok' => true, 'message' => 'Connexion réussie, identifiants acceptés.'] : ['ok' => false, 'message' => sprintf('Identifiants refusés (HTTP %d).', $status)];
        }
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $m, \PREG_SET_ORDER);
        $params = array_column(array_map(static fn ($x) => [strtolower($x[1]), $x[2]], $m), 1, 0);
        if (!isset($params['realm'])) {
            return ['ok' => false, 'message' => 'Authentification du registre non prise en charge.'];
        }
        $token = $this->http->request('GET', $params['realm'], $auth + ['timeout' => 15, 'query' => array_filter(['service' => $params['service'] ?? null])]);
        if (!$auth && \in_array($token->getStatusCode(), [401, 403], true)) {
            return ['ok' => true, 'message' => 'Registre joignable ; pas de jeton anonyme global : renseignez des identifiants pour les images privées.'];
        }
        if (200 !== $token->getStatusCode()) {
            return ['ok' => false, 'message' => sprintf('Jeton refusé par le registre (HTTP %d) : vérifiez les identifiants.', $token->getStatusCode())];
        }

        return ['ok' => true, 'message' => $auth ? 'Connexion réussie, identifiants acceptés.' : 'Registre joignable (jeton anonyme).'];
    }
}
