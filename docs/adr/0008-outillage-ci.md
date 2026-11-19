# 0008 — Outillage de la CI : ligne de base PHPStan et exceptions documentées

Statut : accepté — 2026-11-19

## Contexte

Les guidelines demandent une CI bloquante : PHPStan au niveau max, PHP-CS-Fixer, ESLint strict,
Prettier, `composer audit`, `npm audit`, Semgrep, gitleaks, tests et mises à jour hebdomadaires. Le code
existant n'a jamais été analysé : au niveau max, PHPStan relève environ 1 000 erreurs, presque toutes des
valeurs `mixed` issues des réponses JSON des registres (npm, PyPI, Packagist…) lues sans type.

## Décision

- `.github/workflows/ci.yml` exécute tous ces contrôles sur chaque push, chaque PR et chaque lundi ;
  les images de production y sont construites et analysées par Trivy (HIGH et CRITICAL corrigibles).
  `make lint` reproduit l'analyse statique en local.
- PHPStan tourne au niveau **max** avec une ligne de base (`phpstan-baseline.neon`) : les erreurs réelles
  des niveaux 0 à 6 ont été corrigées, le reste est figé et toute nouvelle erreur bloque. La ligne de base
  se réduit en typant les réponses des registres (DTO ou formes `array{…}`).
- Dependabot plutôt que Renovate : intégré à GitHub, sans application tierce à installer.
- Exceptions, chacune justifiée là où elle est déclarée :
  - Trivy : `CVE-2025-47273`, setuptools embarqué par pip (dernière version) dans l'image Rémus ; la
    fonction vulnérable n'est pas dans cette copie (`.trivyignore`).
  - Semgrep : `missing-user` sur l'étape intermédiaire `runtime` du Dockerfile ; les images publiées se
    terminent par `USER app` (`ci.yml`).
  - gitleaks : secret de développement de la recette Symfony présent dans l'historique, jamais utilisé
    et retiré des fichiers (`.gitleaksignore`).
  - ESLint : `no-autofocus` désactivé (connexion et installation : un seul champ attendu) ; clic sur le
    fond du `<dialog>` sans équivalent clavier, Échap le fermant nativement.

## Conséquences

- La ligne de base masque des erreurs de typage existantes : elle ne doit que décroître ; la régénérer
  pour faire passer une nouvelle erreur est refusé en revue.
- Pas de Vitest ni de Playwright pour l'instant : les tests unitaires du front tournent sous `node --test`
  et les parcours sont vérifiés à la main dans le navigateur.
