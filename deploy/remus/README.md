# Rémus

**Rémus** est le collecteur de **Lupa**. Il permet d'installer des paquets **npm**, **Python (PyPI)**,
**Composer** et des **images Docker** sur un réseau isolé d'Internet. Installé sur une machine connectée,
il télécharge les paquets et toutes leurs dépendances, puis prépare des **bundles signés** à copier sur
une clé USB. De l'autre côté, son jumeau [**Romulus**](https://hub.docker.com/r/iemperor/romulus) importe
ces bundles et sert les dépôts aux postes du réseau isolé.

```
 INTERNET                          CLÉ USB                       RÉSEAU ISOLÉ
┌────────────────────────┐  export  ┌──────────────────┐  import  ┌─────────────────────────────┐
│ Rémus (cette image)    │ ───────► │ bundle signé     │ ───────► │ Romulus                     │
│ listes, dépendances,   │          │ sas-….tar        │          │ npm, PyPI, Composer, Docker │
│ téléchargements vérif. │          │ (découpable)     │          │ pour les postes             │
└────────────────────────┘          └──────────────────┘          └─────────────────────────────┘
```

L'image contient l'application complète, avec le front **Angular**, l'API **Symfony** et le worker des
tâches de fond, servie par **FrankenPHP** sur le port 8080. Elle embarque `npm`, `pip` et `composer`,
qui servent à résoudre les dépendances. Elle n'a besoin d'**aucun service externe** : la base SQLite et
les fichiers sont dans le volume `/data`.

## Fonctionnalités

- **Listes de paquets :** par écosystème, saisies à la main (`express@^4`, `django>=5`, `monolog/monolog:^3`,
  `nginx:1.27`) ou importées depuis un fichier de verrouillage (`package-lock.json`, `requirements.txt`,
  `composer.lock`, `composer.json`). Les dépendances sont résolues par les outils officiels.
- **Modèles de framework :** Rémus exécute le vrai générateur et collecte tout ce qu'il installe. Les commandes
  suivantes fonctionnent ensuite telles quelles hors ligne :
  - `ng new` (Angular, avec et sans SSR) ;
  - `npm create vite` (React, Vue, Svelte, TypeScript) ;
  - `nest new` ;
  - `composer create-project symfony/skeleton`, avec les **recettes Symfony Flex** ;
  - `composer create-project laravel/laravel`.
  
  Des listes prêtes à l'emploi existent aussi pour Python (Django, FastAPI, Flask, data science) et Docker.
- **Python multi-plateformes :** des wheels pour chaque cible voulue. Versions de Python de 3.9 à 3.14 ;
  plateformes Linux glibc ou musl (x86_64, ARM64), Windows et macOS. Les sources (sdist) sont disponibles en option.
- **Docker :** les plateformes à collecter sont au choix (amd64, arm64…). L'index multi-plateformes d'origine
  est conservé, donc `image@sha256:…` reste valide. Les couches communes ne sont téléchargées et transportées qu'une fois.
- **Téléchargements vérifiés :** integrity sha512 pour npm, sha256 pour PyPI, digest des couches Docker,
  shasum Composer.
- **Exports incrémentaux :** seuls les paquets jamais transportés partent. Un export « complet » existe pour un
  nouveau miroir. Un bundle peut être découpé en **parties de 4 Go** pour une clé FAT32.
- **Bundles signés (Ed25519) :** Romulus refuse tout bundle modifié ou produit par un collecteur qu'il n'a pas approuvé.
- **Interface :** en français, thème clair et sombre, adaptée au mobile. Assistant d'installation au premier démarrage.

## Tags

| Tag | Contenu |
|---|---|
| `1.0.0`, `latest` | Première version : npm, PyPI, Composer, Docker, modèles de framework, recettes Symfony Flex, bundles signés et découpables |

Plateforme : `linux/amd64`. Image associée : [`iemperor/romulus`](https://hub.docker.com/r/iemperor/romulus)
(même version recommandée).

---

## Démarrage rapide

```bash
mkdir -p exports && chmod 777 exports
docker run -d --name remus --restart unless-stopped \
  -p 127.0.0.1:8080:8080 \
  -v remus-data:/data \
  -v "$PWD/exports:/exports" \
  iemperor/remus:1.0.0
docker logs remus 2>&1 | grep "Code d'installation"
```

Ouvrez <http://127.0.0.1:8080> : l'assistant d'installation demande ce code, puis crée le compte
administrateur. Aucune variable n'est obligatoire.

---

## Installation avec Docker Compose (recommandée)

### Prérequis

- Une machine **connectée à Internet** (poste de travail ou serveur), avec **Docker 24+** et **Docker Compose v2**.
  Le mode rootless est pris en charge.
- De l'espace disque pour les paquets collectés. Comptez par exemple 500 Mo pour un projet Angular,
  binaires de toutes les plateformes compris, et quelques dizaines de Mo par image Docker de base.
- Une clé USB, ou tout autre support amovible, pour aller jusqu'au réseau isolé.

### 1. Créer le dossier

```bash
mkdir remus && cd remus
mkdir exports
```

### 2. Créer `compose.yaml`

```yaml
name: remus

services:
  remus:
    image: ${REMUS_IMAGE:-iemperor/remus:latest}
    restart: unless-stopped
    ports:
      # Par défaut accessible seulement depuis cette machine
      - "${HTTP_BIND:-127.0.0.1}:${HTTP_PORT:-8080}:8080"
    environment:
      APP_SECRET: ${APP_SECRET:-}
      APP_SECRET_FILE: ${APP_SECRET_FILE:-}
      REMUS_SIGNING_KEY: ${REMUS_SIGNING_KEY:-}
      REMUS_SIGNING_KEY_FILE: ${REMUS_SIGNING_KEY_FILE:-}
      GITHUB_TOKEN: ${GITHUB_TOKEN:-}
      GITHUB_TOKEN_FILE: ${GITHUB_TOKEN_FILE:-}
      DOCKERHUB_USERNAME: ${DOCKERHUB_USERNAME:-}
      DOCKERHUB_TOKEN: ${DOCKERHUB_TOKEN:-}
      DOCKERHUB_TOKEN_FILE: ${DOCKERHUB_TOKEN_FILE:-}
      NPM_REGISTRY: ${NPM_REGISTRY:-https://registry.npmjs.org}
      PYPI_INDEX: ${PYPI_INDEX:-https://pypi.org}
    volumes:
      - data:/data
      # Dossier d'export : la clé USB elle-même, ou un dossier copié ensuite sur la clé
      - ${EXPORT_DIR:-./exports}:/exports
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    read_only: true
    tmpfs:
      - /tmp:size=2g
      - /config:mode=1777
      - /app/var:mode=1777

volumes:
  data:
```

### 3. Créer le fichier `.env` (facultatif)

Toutes les valeurs ont un défaut : ce fichier ne sert qu'à les changer.

```bash
# Version de l'image
REMUS_IMAGE=iemperor/remus:1.0.0

# 127.0.0.1 = cette machine seulement ; 0.0.0.0 = accessible depuis le réseau local
HTTP_BIND=127.0.0.1
HTTP_PORT=8080

# Dossier d'export sur l'hôte : la clé USB montée (ex. /media/moi/CLE) ou un dossier intermédiaire
EXPORT_DIR=./exports

# Facultatif : évite les limites de débit anonymes (voir plus bas)
GITHUB_TOKEN=
DOCKERHUB_USERNAME=
DOCKERHUB_TOKEN=
```

Le package `remus-<version>.tar` contient ces fichiers et un script `./configure.sh`, qui crée ce
`.env` ou le complète lors d'une mise à jour sans modifier vos réglages.

### 4. Démarrer

```bash
docker compose up -d
docker compose logs remus | grep "Code d'installation"
```

Au premier démarrage, Rémus génère ses secrets et sa clé de signature (voir [Secrets](#secrets)), puis
affiche un **code d'installation** à usage unique. Il apparaît aussi avec
`docker compose exec remus php bin/console app:setup:code`.

### 5. Assistant d'installation

Ouvrez <http://127.0.0.1:8080>. L'assistant demande :

1. le **code d'installation** ;
2. le **compte administrateur** (12 caractères minimum pour le mot de passe) ;
3. le **nom du collecteur**, affiché dans Romulus et dans le nom des fichiers de bundle.

Le code est ensuite détruit et l'assistant se ferme définitivement. D'autres comptes se créent en ligne
de commande : `docker compose exec remus php bin/console app:user:create <identifiant>`.

### 6. Collecter et exporter

1. **Listes › Nouvelle liste** : choisissez un écosystème, puis un **modèle** (Angular, Symfony…) ou saisissez des paquets.
2. **Enregistrer et télécharger** : Rémus résout les dépendances et télécharge tout. La progression et le
   journal sont dans **Activité**.
3. **Exports › Créer le bundle** : choisissez « Nouveautés » ou « Complet », et le découpage en parties
   de 4 Go si la clé est en FAT32.
4. Copiez les fichiers `sas-….tar`, ou toutes les parties `.tar.001`, `.002`…, sur la clé USB. Les bundles
   peuvent aussi être téléchargés depuis l'historique des exports.

### 7. Premier import dans Romulus

Au premier import, Romulus affiche l'**empreinte** de la clé de Rémus. Comparez-la avec celle de
**Réglages › Signature des bundles** dans Rémus avant de l'approuver. Pour l'approuver à l'avance, collez
la clé publique de Rémus dans l'assistant d'installation de Romulus, ou dans **Romulus › Réglages**.

---

## Dossier d'export et clé USB

- Le conteneur écrit avec l'**UID 1000**. Le dossier d'export doit lui être accessible en écriture :
  `chown 1000:1000 exports` si votre utilisateur a l'UID 1000, sinon `chmod 777 exports`. Ce dernier cas
  vaut aussi en **Docker rootless**, où l'UID 1000 du conteneur correspond à un sous-UID de votre utilisateur.
- `EXPORT_DIR` peut désigner directement la clé montée, par exemple `/media/moi/CLE`. Si la clé n'est pas
  branchée au démarrage, un avertissement s'affiche dans les journaux et les exports échouent jusqu'à son retour.
- **FAT32** n'accepte pas de fichier de plus de 4 Go : choisissez alors « Parties de 4 Go ». Les parties
  sont réassemblées à l'import. Avec exFAT, NTFS ou ext4, un seul fichier suffit.
- Importez les bundles **dans l'ordre de leurs numéros** : un bundle « Nouveautés » s'appuie sur les
  précédents. Romulus signale tout bundle manquant.

## Variables d'environnement

Aucune n'est obligatoire.

| Variable | Défaut | Rôle |
|---|---|---|
| `NPM_REGISTRY` | `https://registry.npmjs.org` | Registre npm amont (ex. un proxy d'entreprise) |
| `PYPI_INDEX` | `https://pypi.org` | Index Python amont |
| `GITHUB_TOKEN` / `GITHUB_TOKEN_FILE` | — | Jeton GitHub **sans aucune permission** : évite les limites de l'API pour certains paquets Composer |
| `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN` / `DOCKERHUB_TOKEN_FILE` | — | Compte Docker Hub et jeton **en lecture seule** : évite la limite de téléchargements anonymes |
| `APP_SECRET` / `APP_SECRET_FILE` | généré | Secret des sessions, voir [Secrets](#secrets) |
| `REMUS_SIGNING_KEY` / `REMUS_SIGNING_KEY_FILE` | — | Import d'une clé de signature existante (réinstallation) |

Une URL mal formée arrête le démarrage avec la valeur fautive. Les variantes `_FILE` lisent la valeur
dans un fichier, par exemple un *Docker secret* (`/run/secrets/…`).

## Volumes et ports

| Chemin | Contenu |
|---|---|
| `/data` | Base SQLite, paquets téléchargés (stockage par empreinte, sans doublon), clé de signature (`identity/`), secrets (`secrets/`), caches npm, pip et Composer. **À sauvegarder.** |
| `/exports` | Bundles produits (clé USB ou dossier intermédiaire) |

Port **8080** (HTTP). L'image tourne sans privilège, avec un système de fichiers en lecture seule. Elle a
besoin de trois `tmpfs` : `/tmp`, `/config` et `/app/var`.

## Secrets

Rien n'est à configurer. Au premier démarrage, Rémus génère :

- `APP_SECRET`, qui signe les sessions, dans `/data/secrets/app_secret` ;
- la **clé de signature Ed25519** des bundles, dans `/data/identity/secret.key`.

Lors d'une mise à jour, **un secret existant et valide n'est jamais modifié** :

| Situation | Comportement |
|---|---|
| `APP_SECRET` ou `APP_SECRET_FILE` fourni | utilisé ; invalide (moins de 32 caractères, espaces), le démarrage s'arrête avec une explication |
| Secret conservé dans le volume, valide | inchangé |
| Secret conservé dans le volume, invalide | régénéré, l'ancienne valeur est gardée à côté |
| Clé de signature perdue, corrompue, ou différente de `REMUS_SIGNING_KEY` | **démarrage arrêté**, jamais remplacée en silence |

La clé de signature n'est jamais régénérée automatiquement : les Romulus qui l'ont approuvée refuseraient
les bundles d'une nouvelle clé.

**Réinstaller Rémus sur une nouvelle machine** en gardant la même clé : renseignez `REMUS_SIGNING_KEY`
(le contenu de `identity/secret.key` de l'ancien volume), ou `REMUS_SIGNING_KEY_FILE`, avant le premier
démarrage. La clé est importée dans le volume, puis la variable peut être retirée.

## Exploitation

### Mise à jour

```bash
# Dans .env : REMUS_IMAGE=iemperor/remus:<nouvelle version>   (ou ./configure.sh depuis le nouveau package)
docker compose pull
docker compose up -d
```

Les migrations de la base sont appliquées au démarrage. Les secrets, la clé de signature et les paquets
collectés sont conservés.

### Sauvegarde et restauration

Tout est dans le volume `data`. Le plus important est `identity/`, qui contient la clé de signature.

```bash
docker compose stop
docker run --rm -v remus_data:/data -v "$PWD":/backup alpine tar -czf /backup/remus-data.tar.gz -C /data .
docker compose start
```

Restauration sur une machine neuve, avant le premier `up` :

```bash
docker volume create remus_data
docker run --rm -v remus_data:/data -v "$PWD":/backup alpine sh -c "tar -xzf /backup/remus-data.tar.gz -C /data && chown -R 1000:1000 /data"
docker compose up -d
```

Pour ne sauvegarder que l'essentiel, copiez `identity/secret.key` en lieu sûr : avec
`REMUS_SIGNING_KEY`, il suffit à recréer un Rémus que les miroirs reconnaissent.

### Commandes utiles

```bash
docker compose logs -f remus                                   # journaux (application et worker)
docker compose exec remus php bin/console app:user:create bob  # autre compte (mot de passe saisi masqué)
docker compose exec remus php bin/console app:identity:check   # nom et empreinte de la clé de signature
docker compose exec remus php bin/console app:setup:code       # code d'installation (avant le premier compte)
```

## Accès depuis le réseau local

Par défaut, Rémus n'écoute que sur `127.0.0.1`. Pour y accéder depuis d'autres postes, réglez
`HTTP_BIND=0.0.0.0`, de préférence derrière un reverse proxy HTTPS (Caddy, nginx, Traefik). Les cookies
de session sont alors marqués `Secure` automatiquement.

## Sécurité

- **Assistant d'installation** protégé par un code à usage unique, lisible seulement sur le serveur.
- **Comptes :** session par cookie `HttpOnly` et `SameSite=Strict`, limitation des tentatives de connexion.
- **Téléchargements :** chaque fichier est vérifié avec la somme publiée par son registre ; le stockage
  est adressé par sha256.
- **Bundles :** manifeste signé en Ed25519 et sha256 de chaque fichier. Romulus vérifie la signature
  **avant** d'écrire quoi que ce soit.
- **Conteneur :** utilisateur sans privilège (UID 1000), toutes les capabilities retirées, `no-new-privileges`,
  système de fichiers en lecture seule.
- **Hors périmètre :** Rémus ne contrôle pas le **contenu** des paquets (vulnérabilités, code malveillant).
  Faites l'audit voulu, par exemple `npm audit` ou un scanner d'images, avant d'exporter.

## Limites

- **Python :** un paquet publié uniquement en sources (sans wheel) ne peut pas être résolu pour une
  plateforme différente de celle du conteneur. L'option « sources (sdist) » ajoute les sources des versions retenues.
- **npm :** `yarn.lock` et `pnpm-lock.yaml` ne sont pas lus ; saisissez les paquets ou importez un `package-lock.json`.
- **Symfony AssetMapper :** `importmap:require` télécharge Stimulus et Turbo depuis le CDN jsDelivr,
  ce qui échoue hors ligne. Le projet est néanmoins créé et configuré.
- **Docker :** les couches « non redistribuables » des images Windows sont ignorées.

## Dépannage

| Symptôme | Cause et solution |
|---|---|
| `le volume /data n'est pas accessible en écriture` | Volume créé par un autre utilisateur : `docker run --rm -v remus_data:/data alpine chown -R 1000:1000 /data` |
| Les exports échouent, « dossier d'export non accessible » | Clé non montée, ou droits : voir [Dossier d'export](#dossier-dexport-et-clé-usb) |
| `Clé secrète absente…` ou `Clé de signature … corrompue` | Restaurez `identity/secret.key` depuis une sauvegarde, ou fournissez-la par `REMUS_SIGNING_KEY` |
| `toomanyrequests` sur Docker Hub | Limite anonyme atteinte : renseignez `DOCKERHUB_USERNAME` et `DOCKERHUB_TOKEN` |
| Résolution Python « No matching distribution » | Paquet sans wheel pour la cible : changez de version ou retirez la cible |
| Résolution npm en conflit (`ERESOLVE`) | Rémus réessaie avec `--legacy-peer-deps` ; l'option peut aussi être cochée dans la liste |
