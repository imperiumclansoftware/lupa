# 0005 — Comptes tous administrateurs, sans Voter par ressource

Statut : accepté, provisoire — 2026-11-19 (à revoir avec l'introduction de rôles)

## Contexte

Les guidelines exigent un Voter par ressource (contrôle du propriétaire ou du tenant) et des tests IDOR.
Rémus et Romulus sont des outils d'administration mono-organisation : listes, exports, imports et
réglages sont communs à tous les comptes ; aucune ressource n'appartient à un utilisateur.

## Décision

- Tous les comptes ont le rôle `ROLE_USER`, qui donne accès à toute l'administration ; l'accès se
  décide dans `access_control` (refus par défaut, `/api/` réservé aux sessions authentifiées).
- Pas de Voter tant qu'il n'existe qu'un niveau de droit : un Voter qui autoriserait tout utilisateur
  connecté n'apporterait aucune protection.
- Les tests fonctionnels vérifient le refus d'accès sans session sur les routes de l'API.

## Conséquences

- Tout compte peut modifier la liste noire, les dépôts et les exports : les comptes ne sont donnés
  qu'aux administrateurs.
- L'ajout d'un rôle en lecture seule (opérateur) introduira des Voters par action (`EDIT`, `EXPORT`,
  `IMPORT`) et les tests IDOR associés ; cet ADR sera alors remplacé.
