# 0003 — Mots de passe compromis vérifiés par Rémus seulement

Statut : accepté — 2026-11-19

## Contexte

Les guidelines imposent `#[Assert\NotCompromisedPassword]`, qui interroge l'API « Have I Been Pwned »
(k-anonymat : seuls les 5 premiers caractères du SHA-1 sont envoyés). Romulus fonctionne sans aucun
accès sortant : cette vérification y est impossible.

## Décision

- **Rémus** (connecté à Internet) : 12 caractères minimum, `PasswordStrength` (niveau moyen au moins) et
  `NotCompromisedPassword`, à l'assistant d'installation comme dans `app:user:create`.
- **Romulus** : 12 caractères minimum et `PasswordStrength` ; pas de `NotCompromisedPassword`.
- Les mots de passe sont hachés en Argon2id (`algorithm: sodium`).

## Conséquences

- Un mot de passe connu des listes de fuites reste possible sur Romulus s'il est assez robuste selon
  `PasswordStrength` ; la documentation recommande un gestionnaire de mots de passe.
- Une liste locale de mots de passe compromis pourrait être transportée par les bundles : non retenu
  (volume de plusieurs Go).
