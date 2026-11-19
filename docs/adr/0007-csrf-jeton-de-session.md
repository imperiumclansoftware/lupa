# 0007 — Protection CSRF par jeton de session

Statut : accepté — 2026-11-19

## Contexte

L'API est appelée par la SPA Angular du même site, avec le cookie de session (`SameSite=Strict`).
Les guidelines exigent un jeton CSRF transmis par `withXsrfConfiguration` et vérifié par Symfony sur
toute requête qui modifie des données.

## Décision

- Symfony génère un jeton aléatoire de 256 bits par session et le dépose dans le cookie `XSRF-TOKEN`
  (lisible par le front, `SameSite=Strict`, `Secure` en HTTPS).
- Angular le renvoie dans l'en-tête `X-XSRF-TOKEN` (`withXsrfConfiguration`) ; Symfony le compare à la
  valeur de la session avec `hash_equals()` pour toute méthode autre que GET, HEAD et OPTIONS sous
  `/api/`, y compris la connexion. Sinon : 403.
- Les registres (ADR 0001) sont en lecture seule et ne sont pas concernés.

## Conséquences

- Un client de l'API doit d'abord appeler `GET /api/session` pour obtenir le jeton.
- Défense en profondeur avec `SameSite=Strict` et l'obligation d'un corps JSON.
