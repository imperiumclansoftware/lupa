# 0009 — Double authentification facultative

Statut : accepté — 2026-11-19

## Contexte

Les guidelines demandent de proposer le MFA (TOTP ou WebAuthn, `scheb/2fa-bundle`) et de l'imposer aux
administrateurs. Romulus est installé sur des réseaux isolés où les téléphones sont souvent interdits
ou laissés à l'entrée ; une double authentification obligatoire y bloquerait l'accès à l'outil.

## Décision

- Double authentification **facultative pour tous les comptes**, administrateurs compris : chacun
  l'active depuis « Mon compte » (mot de passe redemandé, QR code puis code de confirmation).
- TOTP (RFC 6238, 30 s, 6 chiffres, `scheb/2fa-totp`) : les applications d'authentification
  fonctionnent hors ligne. Dix codes de secours à usage unique, conservés en SHA-256, affichés une seule
  fois et régénérables.
- Connexion en deux étapes en JSON (`/api/login` puis `/api/2fa/check`) ; tant que le code n'est pas
  donné, l'API reste fermée. Après cinq codes faux, la session est fermée : il faut redonner le mot de
  passe, dont les tentatives sont limitées (`login_throttling`, qui compte aussi les codes faux).
- Téléphone perdu sans code de secours : un administrateur réinitialise la double authentification du
  compte (page « Comptes »), ou la console du serveur (`app:user:2fa-reset`). Chaque événement est
  journalisé sur le canal `audit`.
- Le secret TOTP est stocké en clair dans la base SQLite : elle se trouve dans le même volume que
  `APP_SECRET`, un chiffrement avec cette clé n'apporterait rien contre une copie du volume.
- WebAuthn n'est pas proposé : il exige HTTPS et une origine stable, ce que les installations en HTTP
  (ADR 0002) n'offrent pas toujours.

## Conséquences

- Écart à la règle « obligatoire pour les administrateurs » : la documentation recommande de l'activer
  sur les comptes administrateurs partout où un téléphone ou une clé matérielle TOTP est admis.
- Une option d'obligation par rôle pourra être ajoutée sans changer le stockage.
