# 0004 — Réinitialisation des mots de passe par la console du serveur

Statut : accepté — 2026-11-19

## Contexte

Les guidelines demandent `symfonycasts/reset-password-bundle` (jeton haché envoyé par courriel).
Rémus et Romulus n'envoient pas de courriel, et Romulus n'a pas de messagerie sur le réseau isolé.
Les comptes sont peu nombreux et tous administrateurs.

## Décision

- Pas de réinitialisation en libre-service : un mot de passe se change avec
  `php bin/console app:user:create <identifiant>`, qui exige un accès au serveur (Docker).
- La commande applique la même politique de mots de passe que l'assistant (ADR 0003).
- Le premier compte se crée par l'assistant, protégé par un code à usage unique affiché seulement dans
  les journaux du serveur et stocké haché.

## Conséquences

- Pas de jeton de réinitialisation exposé sur le réseau, pas de dépendance à un serveur de messagerie.
- Un utilisateur qui oublie son mot de passe passe par l'administrateur du serveur.
