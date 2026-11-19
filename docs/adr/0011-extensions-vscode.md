# 0011 — Extensions VS Code : sources, choix des versions et marketplace

Statut : accepté — 2026-11-19

## Contexte

Les postes du réseau isolé utilisent VS Code (Microsoft), VSCodium ou code-server. Deux galeries publiques
existent : Open VSX (Fondation Eclipse, sans restriction d'usage) et le Visual Studio Marketplace, plus
complet (extensions Microsoft : C# Dev Kit, Pylance, Remote…) mais dont les conditions réservent l'usage
aux produits Microsoft. Les deux parlent le même protocole de galerie (`extensionquery`).

## Décision

- Nouvel écosystème `vscode` dans le format de bundle : la structure du manifeste ne change pas, mais un
  Romulus antérieur refuse un bundle qui contient des extensions (écosystème inconnu). Mettre Romulus à
  jour avant d'importer de tels bundles.
- Source choisie par liste : Open VSX (par défaut), le Marketplace, ou l'un puis l'autre. L'interface
  rappelle les conditions du Marketplace ; le choix revient à l'exploitant.
- Version : la plus récente stable (préversions en option) qui respecte la contrainte et, si une version
  de VS Code est indiquée, son moteur. Pour chaque plateforme demandée (Linux x64 par défaut), la
  construction propre à la plateforme si elle existe, sinon l'universelle — comme VS Code, qui sinon
  installerait une extension sans son code natif.
- Intégrité : sha256 publié par le Marketplace (propriété `VsixSha256`) ou par Open VSX (fichier
  `.sha256`) ; sans somme publiée, VSIX reçu par HTTPS et signalé dans le journal.
- Romulus sert le protocole de galerie lui-même (`/vscode/…`) : l'éditeur cherche, installe et met à jour
  sans modification de l'extension ni proxy. Les identifiants d'origine (GUID) sont conservés pour que les
  extensions déjà installées se mettent à jour. Les versions en quarantaine ne sont pas annoncées.

## Conséquences

- VS Code de Microsoft se configure en modifiant son product.json, à refaire après chaque mise à jour ;
  VSCodium et code-server se configurent par variables d'environnement.
- Les extensions qui téléchargent des outils à leur premier lancement (hors galerie) restent à traiter
  au cas par cas.
- Les alertes OSV ne couvrent pas les extensions (OSV.dev ne les recense pas).
