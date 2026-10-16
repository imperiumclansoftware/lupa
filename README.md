# Lupa — Rémus & Romulus

Dépôts de paquets **hors ligne** pour un réseau isolé d'Internet : npm, Python (PyPI), Composer,
images Docker, paquets système Debian (apt) et RPM (AlmaLinux, CentOS : dnf, yum), collections et rôles
Ansible Galaxy, providers et modules Terraform. Deux applications web jumelles, reliées par une clé USB :

- **Rémus**, le collecteur : il franchit la limite et rapporte les paquets d'Internet ;
- **Romulus**, le miroir : il garde l'enceinte du réseau isolé et ne laisse entrer que ce qui est signé.

Images Docker : [`iemperor/remus`](https://hub.docker.com/r/iemperor/remus) et [`iemperor/romulus`](https://hub.docker.com/r/iemperor/romulus).
Sources : [github.com/imperiumclansoftware/lupa](https://github.com/imperiumclansoftware/lupa), licence [MIT](LICENSE)
(le nom technique interne reste `sas` : bundle partagé `sas/bundle`, préfixe des fichiers exportés).

```
 INTERNET                               CLÉ USB                       RÉSEAU ISOLÉ
┌─────────────────────────┐   export   ┌──────────────────┐  import  ┌──────────────────────────────┐
│ Rémus (collecteur)      │ ─────────► │ sas-…-000007.tar │ ───────► │ Romulus (miroir)             │
│ listes, lockfiles       │            │ manifeste signé  │          │ vérifie signature + sha256   │
│ résolution des dépend.  │            │ + fichiers       │          │ sert npm, PyPI, Composer,    │
│ téléchargement vérifié  │            │   (sha256)       │          │ Docker aux postes            │
└─────────────────────────┘            └──────────────────┘          └──────────────────────────────┘
```

- **Rémus, le collecteur** (`collector/`) : on décrit des **listes** de paquets (saisie libre ou fichier de
  verrouillage : `package-lock.json`, `requirements.txt`, `composer.lock`/`composer.json`), il résout
  toutes les dépendances avec les outils officiels (npm, pip, Composer) ou l'API des registres Docker,
  télécharge en vérifiant les sommes de contrôle publiées et range tout dans un stockage par
  empreinte sha256 (un fichier commun à plusieurs paquets ou images n'est stocké qu'une fois).
- **Export** : un *bundle* tar contenant un manifeste signé (Ed25519) et les fichiers. Les exports
  sont **incrémentaux** (seulement ce qui n'a jamais été transporté) et peuvent être **découpés** en
  parties de 4 Go pour une clé FAT32.
- **Romulus, le miroir** (`mirror/`) : importe un bundle seulement si sa signature provient d'un collecteur
  approuvé et que chaque fichier a la bonne empreinte, puis sert des registres compatibles avec les
  clients standards — aucun outil à installer sur les postes.

| Écosystème | Ce qui est collecté | Côté poste client |
|---|---|---|
| npm | arbre complet de `npm install`, y compris les dépendances optionnelles de toutes les plateformes | `npm config set registry http://miroir/npm/` |
| Python | wheels pour chaque cible choisie (version de Python × Linux/Windows/macOS/musl), sdist en option | `pip … --index-url http://miroir/pypi/simple/` (PEP 503 / 691), uv |
| Composer | archives des versions résolues (contrainte de version PHP en option) | dépôt `composer` `http://miroir/composer` (format p2) |
| Symfony Flex | recettes des paquets collectés (officielles et contrib) | `SYMFONY_ENDPOINT=http://miroir/composer/flex` |
| Docker | index multi-plateformes d'origine + plateformes choisies + attestations | `docker pull miroir/library/nginx:1.27` ou `registry-mirrors` |
| Debian 12, 13 | paquets choisis et leurs dépendances (Depends, Pre-Depends, Recommends en option), amd64/arm64 | `deb [signed-by=…/romulus.gpg] http://miroir/deb/debian-12 bookworm main` |
| Ansible Galaxy | collections (dépendances comprises, sha256 vérifié) et rôles ; requirements.yml accepté | `ansible-galaxy collection install -s http://miroir/galaxy/ …` |
| Terraform | providers des plateformes choisies (SHA256SUMS signé vérifié), modules (GitHub), binaire terraform ; .terraform.lock.hcl accepté | `network_mirror` (HTTPS) ou `~/.terraform.d/plugins` ; modules par URL ou registre |
| AlmaLinux 8/9/10, CentOS Stream 9/10, CentOS 7/8 (vault) | paquets choisis et leurs dépendances (capacités, fichiers, dépendances booléennes, streams de modules EL8/EL9) | `baseurl=http://miroir/rpm/almalinux-9/$basearch/` (`/rpm/<distribution>/romulus.repo`) |

## Recherche, motifs et sécurité

- **Recherche** (formulaire de liste) : paquets et images des registres publics, versions disponibles,
  choix de la contrainte (`collector/src/Search/PackageSearch.php`).
- **Motifs** `*` et `?` (`@syncfusion/ej2-*angular*@^29`, `symfony/*-bundle:^7.3`, `node:24-alpine*`) :
  développés au moment de la mise à jour (`PatternExpander`). Les paquets sans version compatible sont
  écartés avec les règles exactes de chaque écosystème (`VersionFilter` : semver de npm, composer/semver,
  packaging de pip).
- **Paquets système** (`collector/src/System/`, `DebResolver`, `RpmResolver`) : Rémus lit les index
  officiels après en avoir vérifié la signature avec gpgv et les clés livrées (`collector/resources/keys`,
  aucune clé téléchargée) : `InRelease` Debian, `repomd.xml.asc` RPM. Il résout ensuite lui-même les
  dépendances comme apt ou dnf le feraient sur un système vide. Les index RPM sont lus dans les bases
  `primary.sqlite` ; ceux d'EL8/EL9 dans `modules.yaml` (streams par défaut, contextes). Romulus
  (`mirror/src/Registry/SystemRepositories.php`) régénère `Packages`/`Release` et `repodata`. Il signe les
  index apt avec sa propre clé OpenPGP Ed25519 (`OpenPgp.php`, en PHP, sans gpg). Les RPM gardent la
  signature de leur distribution, vérifiée par dnf sur le poste.
- **Ansible Galaxy** (`GalaxyResolver`) : API v3 de galaxy.ansible.com, dépendances suivies
  (contraintes évaluées avec composer/semver, `Constraint`). Romulus (`GalaxyController`) sert une API v3
  minimale compatible avec ansible-galaxy. Les rôles viennent de GitHub et s'installent par leur URL.
- **Terraform** (`TerraformResolver`) : le fichier SHA256SUMS de chaque provider est vérifié avec gpgv,
  d'abord avec la clé HashiCorp livrée, sinon avec la clé de l'éditeur publiée par le registre. Les
  modules GitHub sont réempaquetés, module à la racine. Romulus (`TerraformController`) sert le
  protocole « network mirror », une archive « filesystem mirror » et le registre de modules.
- **Liste noire** (`collector/src/Blacklist/`) : versions précises refusées par les résolveurs et exclues
  des exports ; liste complète et révision dans le manifeste signé (`Manifest::$blacklist`). Romulus
  l'applique à l'import (`mirror/src/Quarantine/`) et ses registres filtrent les versions en quarantaine.
  Identité commune des versions : `shared/src/PackageKey.php` (noms PEP 503, `v1.2.0` = `1.2.0`, `nginx` =
  `library/nginx`…).
- **Dépôts sources privés** (`collector/src/Source/`) : `.npmrc` par portée, options d'index de pip,
  `repositories` et `COMPOSER_AUTH` de Composer, identifiants Docker par registre ; le `Downloader` ajoute
  l'authentification d'après l'hôte. Secrets chiffrés par `SecretBox`.
- **Sécurité** : analyse OSV.dev après chaque mise à jour et à la demande (`collector/src/Audit/`), page
  Sécurité dans Rémus, alertes copiées dans les bundles et affichées par Romulus.

## Modèles de framework

Dans « Nouvelle liste », un **modèle** collecte tout ce dont une commande de création de projet a besoin,
pour qu'elle fonctionne ensuite telle quelle sur le réseau isolé :

| Modèle | Commande hors ligne | Comment Rémus le collecte |
|---|---|---|
| Angular | `npx @angular/cli@22 new app` | exécute `ng new` (avec et sans SSR) + la CLI |
| React, Vue, Svelte, TypeScript (Vite) | `npm create vite@latest app -- --template react-ts` | modèle de create-vite + create-vite |
| NestJS | `npx @nestjs/cli new app` | exécute `nest new` + la CLI |
| Symfony | `composer create-project symfony/skeleton:"8.1.*" app` puis `composer require webapp` | skeleton, webapp-pack, dépendances de dev, **recettes Flex** |
| Laravel | `composer create-project laravel/laravel:^12 app` | squelette et dépendances de dev |

Python et Docker proposent des listes prédéfinies (Django, FastAPI, Flask, data science, images de base…).

Limite connue : avec AssetMapper (`symfony/webapp-pack`), l'étape `importmap:require` télécharge les
bibliothèques JavaScript (Stimulus, Turbo) depuis le CDN jsDelivr et échoue hors ligne ; le projet est
néanmoins créé et configuré.

## Développement

Prérequis : Docker (rootless conseillé), comme le projet Constellation.

```bash
make up                        # .env, images, conteneurs, migrations
make user app=collector        # compte du collecteur (mot de passe saisi masqué)
make user app=mirror           # compte du miroir
```

| URL | Service |
|---|---|
| http://collecte.localhost:8090 | Rémus, le collecteur (Angular + API Symfony), avec accès Internet |
| http://miroir.localhost:8090 | Romulus, le miroir, sur un réseau Docker **sans accès Internet** |
| `localhost:8090` | Miroir aussi, pour `docker pull localhost:8090/library/alpine:3.20` sans réglage du démon |

Le dossier `./usb` joue le rôle de la clé : le collecteur y exporte, le miroir y lit.
Les machines de test placées sur le réseau `sas_isolated` joignent le miroir à l'adresse
`http://miroir.test` ; elles n'ont pas Internet, ce qui permet de vérifier un vrai fonctionnement hors ligne :

```bash
docker run --rm --network sas_isolated node:24-alpine sh -c \
  'cd /tmp && npm init -y && npm install express --registry http://miroir.test/npm/'
```

Commandes utiles : `make test` (PHPUnit des deux applications + compilation des fronts),
`make workers` (à lancer après avoir modifié le code exécuté par les tâches de fond),
`make logs s=collector-worker`, `make console app=mirror c="debug:router"`. `make` seul liste tout.

### Organisation du code

```
shared/      bibliothèque commune (sas/bundle) : format du bundle, tar découpable, signature, stockage sha256
collector/   Symfony : listes, résolveurs (src/Resolver), exports (src/Job/ExportRunner.php)
mirror/      Symfony : import (src/Job/ImportRunner.php), registres (src/Controller/*RegistryController.php)
frontend/    Angular : projects/collector, projects/mirror, projects/shared (composants communs)
docker/      images de développement et de production
deploy/      compose et guides d'installation de production
```

Les deux applications utilisent SQLite (aucun serveur de base à installer sur le réseau isolé) et
Symfony Messenger pour les tâches longues (résolution, export, import), exécutées par un worker.

## Production

Deux images publiées sur Docker Hub, livrées dans deux packages séparés :

| Image | Package | Documentation (page Docker Hub) |
|---|---|---|
| [`iemperor/remus`](https://hub.docker.com/r/iemperor/remus) | `dist/remus-VERSION.tar` : compose, `.env.example`, `configure.sh` | [deploy/remus/README.md](deploy/remus/README.md) |
| [`iemperor/romulus`](https://hub.docker.com/r/iemperor/romulus) | `dist/romulus-VERSION.tar` : **image comprise** (installation sans Internet), compose, `.env.example`, `configure.sh`, sommes de contrôle | [deploy/romulus/README.md](deploy/romulus/README.md) |

```bash
make images VERSION=1.0.0     # construit iemperor/remus et iemperor/romulus (1.0.0 et latest)
make push VERSION=1.0.0       # publie sur Docker Hub (après « docker login »)
make kits VERSION=1.0.0       # dist/remus-1.0.0.tar et dist/romulus-1.0.0.tar
```

Le contenu de `deploy/*/README.md` est à recopier dans l'onglet « Overview » de chaque dépôt Docker Hub.

Chaque image contient le front, l'API et le worker (FrankenPHP), tourne sans privilège avec un système
de fichiers en lecture seule, et garde toutes ses données dans le volume `/data`.

**Aucune variable obligatoire.** Au premier démarrage, les secrets sont générés et conservés dans le
volume (`/data/secrets`, clé de signature de Rémus dans `/data/identity`) ; un code d'installation
s'affiche dans les journaux pour l'assistant web. Lors d'une mise à jour, un secret existant et valide
n'est jamais modifié :

| Situation | Comportement |
|---|---|
| Secret fourni (`APP_SECRET`, `APP_SECRET_FILE` pour les Docker secrets) | utilisé ; s'il est invalide, le démarrage s'arrête avec une explication |
| Secret conservé dans le volume, valide | inchangé |
| Secret conservé dans le volume, invalide | régénéré, l'ancienne valeur est gardée à côté |
| Clé de signature de Rémus perdue, corrompue ou différente de `REMUS_SIGNING_KEY` | démarrage arrêté, jamais remplacée en silence (les miroirs lui font confiance) |
| `PUBLIC_URL`, `NPM_REGISTRY`, `PYPI_INDEX` mal formées | démarrage arrêté avec la valeur fautive |
| Dossier d'export ou des clés USB inaccessible | avertissement dans les journaux |

Les kits contiennent `configure.sh`, qui crée le `.env` ou le complète lors d'une mise à jour
(nouvelles variables, nouvelle version de l'image) sans toucher aux réglages existants. En
développement, `make up` fait de même avec le `.env` du projet.

## Sécurité

- La clé USB est le seul lien avec l'extérieur, donc le point à protéger : le miroir n'accepte que des
  bundles signés par une clé approuvée (empreinte comparée à l'œil au premier import), vérifie la
  signature **avant** d'écrire quoi que ce soit, puis l'empreinte sha256 de chaque fichier.
- Le collecteur vérifie à chaque téléchargement les sommes publiées par le registre (integrity sha512
  npm, sha256 PyPI, digest des couches Docker, shasum Composer quand il est fourni).
- Interfaces d'administration protégées par mot de passe (session, cookie `SameSite=Strict`, limitation
  des tentatives). Les registres sont en lecture seule et accessibles sans authentification sur le
  réseau isolé, comme un registre interne classique.
- Hors périmètre : Sas ne contrôle pas le **contenu** des paquets (vulnérabilités, malveillance). Un
  `npm audit` ou un scanner d'images reste à faire côté collecteur avant l'export si nécessaire.

## Limites connues

- Python : un paquet publié uniquement en sources (sans wheel) ne peut pas être résolu pour une cible
  autre que celle du collecteur ; l'option « sources (sdist) » ajoute les sources des versions retenues.
- npm : yarn.lock et pnpm-lock.yaml ne sont pas lus (utiliser les noms de paquets ou un package-lock.json).
- Docker : les couches « non redistribuables » (images Windows) sont ignorées.
- Paquets système : seuls les dépôts officiels des distributions sont pris en charge, pas encore EPEL ni
  les dépôts tiers (Docker CE, PGDG…), ni Ubuntu. L'analyse de sécurité OSV.dev ne couvre pas encore ces
  paquets. Les dépendances sont résolues pour un système vide : la bibliothèque C et les autres paquets de
  base sont aussi collectés, ce qui garantit l'installation sur un poste moins à jour.
- Ansible : les dépendances des rôles (meta/main.yml) ne sont pas suivies, ni les collections et rôles
  hors Galaxy (git, URL). Terraform : les modules qu'un module appelle ne sont pas suivis ; seuls les
  modules hébergés sur GitHub ou en archive .tar.gz sont pris en charge. Le miroir réseau de providers
  exige HTTPS ; en HTTP, utiliser l'archive /terraform/providers.tar.
- Liste noire : une version interdite n'est pas remplacée automatiquement. Si npm, pip ou Composer
  l'avaient retenue comme dépendance, elle manque simplement (le journal la signale) ; fixez une autre
  version dans la liste. La quarantaine bloque les manifestes Docker, pas les couches partagées.
- Dépôts privés : la recherche et les motifs (`*`) interrogent les registres publics (sauf les versions
  npm d'une portée privée). Saisissez les paquets privés par leur nom exact.
