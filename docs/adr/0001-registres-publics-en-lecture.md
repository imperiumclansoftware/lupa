# 0001 — Registres de Romulus accessibles sans authentification, en lecture seule

Statut : accepté — 2026-11-19

## Contexte

Les guidelines demandent un refus par défaut : toute route non listée exige une authentification.
Romulus sert des registres (`/npm`, `/pypi`, `/composer`, `/v2`, `/deb`, `/rpm`, `/galaxy`, `/terraform`)
aux postes du réseau isolé. Les clients de ces registres (npm, pip, Composer, Docker, apt, dnf,
ansible-galaxy, Terraform) n'ont pas de mécanisme d'authentification commun, et leur configuration
avec des identifiants poserait des secrets sur chaque poste et dans chaque CI.

## Décision

- Les routes de registre sont publiques et en **lecture seule** : aucune ne modifie de donnée ;
  `docker push`, `npm publish` et tout envoi sont refusés, et les POST de npm (`npm audit`) reçoivent
  une réponse vide.
- Elles n'exposent que des paquets importés depuis des bundles signés par un collecteur approuvé, hors
  quarantaine : aucune donnée de l'application (comptes, journaux, configuration).
- Les fichiers ne sont servis que par empreinte et seulement s'ils appartiennent à un paquet du dépôt
  demandé (pas d'accès arbitraire au stockage).
- L'administration (`/api/*`) reste protégée : refus par défaut, session obligatoire.
- Le cloisonnement se fait au niveau réseau : Romulus est installé sur le réseau isolé, derrière ses
  règles de filtrage.

## Conséquences

- Toute machine qui atteint Romulus peut lire les paquets : c'est le comportement attendu d'un dépôt
  interne, à prendre en compte si Romulus est exposé au-delà du réseau isolé.
- Une authentification des registres (jeton par poste) pourra être ajoutée plus tard sans remettre en
  cause cette décision ; elle ferait l'objet d'un nouvel ADR.
