# Décisions d'architecture (ADR)

Écarts assumés aux guidelines sécurité Symfony / Angular de l'équipe, et choix structurants.
Chaque ADR : contexte, décision, conséquences. Un ADR remplacé n'est pas supprimé : il passe au statut « remplacé par ».

| N° | Décision | Statut |
|---|---|---|
| [0001](0001-registres-publics-en-lecture.md) | Registres de Romulus accessibles sans authentification, en lecture seule | Accepté |
| [0002](0002-http-par-defaut-https-par-proxy.md) | HTTP par défaut, HTTPS assuré par un reverse proxy | Accepté (provisoire) |
| [0003](0003-mots-de-passe-compromis-hors-ligne.md) | Mots de passe compromis vérifiés par Rémus seulement | Accepté |
| [0004](0004-reinitialisation-par-la-console.md) | Réinitialisation des mots de passe par la console du serveur | Accepté |
| [0005](0005-administrateurs-sans-voter.md) | Comptes tous administrateurs, sans Voter par ressource | Accepté (provisoire) |
| [0006](0006-identifiants-entiers-provisoires.md) | Identifiants entiers en attendant la migration en UUID v7 | Accepté (provisoire) |
| [0007](0007-csrf-jeton-de-session.md) | Protection CSRF par jeton de session (double envoi cookie / en-tête) | Accepté |
| [0008](0008-outillage-ci.md) | Outillage de la CI : ligne de base PHPStan et exceptions documentées | Accepté |
