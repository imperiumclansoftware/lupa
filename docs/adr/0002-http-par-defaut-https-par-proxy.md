# 0002 — HTTP par défaut, HTTPS assuré par un reverse proxy

Statut : accepté, provisoire — 2026-11-19 (à remplacer par le HTTPS natif)

## Contexte

Les guidelines exigent un service uniquement en HTTPS, avec HSTS. Rémus et Romulus sont installés sur
des machines internes, souvent sans nom public ni autorité de certification ; Romulus, sur un réseau
sans Internet, ne peut pas obtenir de certificat Let's Encrypt.

## Décision

- Les images écoutent en HTTP sur le port 8080 ; la documentation (section « HTTPS ») décrit la mise en
  place d'un reverse proxy TLS (Caddy, Nginx, Traefik) avec `PUBLIC_URL` et `TRUSTED_PROXIES`.
- Aucun proxy n'est cru par défaut (`TRUSTED_PROXIES=127.0.0.1`) : un client direct ne peut pas
  falsifier `X-Forwarded-For` pour contourner la limitation des connexions. L'administrateur déclare
  l'adresse de son proxy telle que le conteneur la voit.
- Derrière un proxy HTTPS déclaré (`X-Forwarded-Proto: https`), les applications posent
  `Strict-Transport-Security`, des cookies `Secure` et le préfixe `__Host-` pour la session.
- Les cookies restent `HttpOnly` et `SameSite=Strict` dans tous les cas.

## Conséquences

- Une installation déjà derrière un proxy HTTPS garde une session fonctionnelle après mise à jour, mais
  sans cookie `Secure` tant que `TRUSTED_PROXIES` n'est pas renseigné ; l'image l'indique au démarrage.
- Sans proxy, les échanges ne sont pas chiffrés sur le réseau interne : à réserver aux réseaux de
  confiance, ce que la documentation signale.
- Prochaine étape : HTTPS natif dans l'image (certificat fourni ou PKI interne), puis HTTP réservé à la
  redirection ; cet ADR sera alors remplacé.
