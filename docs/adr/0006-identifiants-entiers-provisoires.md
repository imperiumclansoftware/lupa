# 0006 — Identifiants entiers en attendant la migration en UUID v7

Statut : accepté, provisoire — 2026-11-19

## Contexte

Les guidelines exigent des UUID v7 pour toute entité exposée. Les entités de Rémus et Romulus utilisent
des entiers auto-incrémentés, visibles dans l'API (`/api/lists/6`). Le risque principal, l'énumération
de ressources d'autres utilisateurs, est absent ici (ADR 0005), mais les identifiants révèlent des
volumes (nombre de listes, d'exports).

## Décision

- Dette acceptée temporairement ; migration planifiée : clés UUID v7 (`UuidType`, `BINARY(16)` sous
  SQLite), reprise des données existantes par migration Doctrine, routes avec `Requirement::UUID_V7`,
  front adapté.
- Les identifiants déjà publics par conception restent tels quels : numéro de séquence des bundles,
  UUID des exports (déjà v4), empreintes sha256.

## Conséquences

- Aucune nouvelle entité ne doit être créée avec un identifiant entier.
