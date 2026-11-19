# 0010 — Rôles : lecteur, opérateur, administrateur

Statut : accepté — 2026-11-19 (remplace [0005](0005-administrateurs-sans-voter.md))

## Contexte

Jusqu'ici tout compte était administrateur (ADR 0005). Les équipes veulent confier le travail courant
(préparer les listes, exporter, importer) ou la simple consultation sans donner accès aux comptes, aux
réglages, à la liste noire ni aux clés de confiance.

## Décision

- Trois rôles hiérarchiques (`role_hierarchy`) : `ROLE_READER` < `ROLE_OPERATOR` < `ROLE_ADMIN`.
  - Lecteur : consulte tout.
  - Opérateur : en plus, crée et modifie les listes, lance mises à jour et analyses, crée les exports
    (Rémus) ; importe les bundles de collecteurs déjà approuvés (Romulus).
  - Administrateur : en plus, comptes, réglages, liste noire, dépôts sources, suppression de listes,
    clés de confiance et approbation d'un nouveau collecteur.
- Refus par défaut dans `access_control` : toute écriture sur `/api/` exige au moins `ROLE_OPERATOR`,
  `/api/users` exige `ROLE_ADMIN` ; les actions d'administration portent en plus `#[IsGranted('ROLE_ADMIN')]`.
- Pas de Voter par ressource : aucune ressource n'appartient à un utilisateur (outil mono-organisation),
  les droits dépendent de l'action et non de l'objet. Les tests fonctionnels essaient chaque action
  d'écriture avec les trois rôles.
- Garde-fous : un administrateur ne change ni son propre rôle ni ne supprime son compte, et il reste
  toujours au moins un administrateur. Les comptes existants deviennent administrateurs à la migration ;
  `app:user:create --role` choisit le rôle en console.
- Le front masque les actions que le rôle ne permet pas (ergonomie seulement : l'API décide).

## Conséquences

- Un Voter deviendra nécessaire si des ressources acquièrent un propriétaire (listes personnelles,
  plusieurs organisations sur un même Rémus).
