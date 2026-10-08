# Romulus

**Romulus** est le miroir hors ligne de **Lupa**. Sur un réseau isolé d'Internet, il sert aux postes
de travail des dépôts **npm**, **Python (PyPI)**, **Composer** (avec les recettes **Symfony Flex**) et un
registre **Docker**. Son contenu arrive par clé USB, sous forme de **bundles signés** produits par son
jumeau [**Rémus**](https://hub.docker.com/r/iemperor/remus), le collecteur installé côté Internet.

```
 INTERNET                          CLÉ USB                       RÉSEAU ISOLÉ
┌────────────────────────┐  export  ┌──────────────────┐  import  ┌─────────────────────────────┐
│ Rémus                  │ ───────► │ bundle signé     │ ───────► │ Romulus (cette image)       │
│ listes, dépendances,   │          │ sas-….tar        │          │ npm, PyPI, Composer, Docker │
│ téléchargements vérif. │          │ (découpable)     │          │ pour les postes             │
└────────────────────────┘          └──────────────────┘          └─────────────────────────────┘
```

Les postes utilisent leurs outils habituels sans rien installer : `npm install`, `pip install`,
`composer require`, `docker pull`. Il suffit de les faire pointer vers Romulus. Des commandes comme `ng new` ou
`composer create-project symfony/skeleton` fonctionnent aussi, si Rémus a collecté le modèle correspondant.

L'image contient l'application complète, avec le front **Angular**, l'API **Symfony** et le worker
d'import, servie par **FrankenPHP** sur le port 8080. Elle n'a besoin d'**aucun accès à Internet** ni
d'aucun service externe : la base SQLite et les paquets sont dans le volume `/data`.

## Fonctionnalités

- **Import par clé USB :** les bundles de la clé sont détectés automatiquement. Les bundles découpés
  (`.tar.001`, `.002`…) sont réassemblés, et une partie manquante est signalée. Un envoi par le navigateur,
  par morceaux et avec reprise, existe aussi pour les fichiers de plusieurs Go.
- **Import sûr :**
  - la signature Ed25519 est vérifiée **avant** toute écriture ;
  - seuls les collecteurs approuvés sont acceptés, après comparaison de l'empreinte au premier import ;
  - l'empreinte sha256 de chaque fichier est vérifiée ;
  - un bundle précédent non importé est détecté.
- **Registres servis :**
  - npm (`/npm/`), pour npm, yarn 1 et pnpm ;
  - index Python « simple » PEP 503/691 (`/pypi/simple/`), pour pip et uv ;
  - Composer 2 (`/composer`) ;
  - recettes Symfony Flex (`/composer/flex`) ;
  - registre Docker OCI en lecture seule (`/v2/`), utilisable comme *registry mirror* de Docker Hub.
- **Interface :**
  - tableau de bord, historique des imports, navigation dans les paquets avec la commande d'installation de chacun ;
  - page **Utilisation**, qui donne la configuration exacte de chaque outil avec l'adresse du serveur ;
  - en français, thème clair et sombre ;
  - assistant d'installation au premier démarrage.

## Tags

| Tag | Contenu |
|---|---|
| `1.0.0`, `latest` | Première version : npm, PyPI, Composer, Symfony Flex, Docker, import signé depuis clé USB ou navigateur |

Plateforme : `linux/amd64`. Image associée : [`iemperor/remus`](https://hub.docker.com/r/iemperor/remus)
(même version recommandée).

---

## Installation sur le réseau isolé

### Prérequis

- Un serveur Linux du réseau isolé avec **Docker 24+** et **Docker Compose v2**. Le mode rootless est pris en charge.
- De l'espace disque pour tout ce que Rémus exporte : chaque fichier n'est stocké qu'une fois.
- Un moyen de brancher les clés USB sur ce serveur, ou un navigateur pour envoyer les bundles.

### 1. Apporter l'image sur le serveur

Le serveur n'a pas Internet : l'image voyage elle aussi par clé USB.

**Avec le package `romulus-<version>.tar`**, qui contient l'image, `compose.yaml`, `.env.example`,
`configure.sh`, ce guide et des sommes de contrôle :

```bash
tar -xf romulus-1.0.0.tar && cd romulus-1.0.0
sha256sum -c SHA256SUMS                  # la copie sur la clé n'a rien abîmé
docker load -i romulus-1.0.0.tar.gz
./configure.sh                           # crée .env ; à relire
```

**Ou depuis Docker Hub**, sur une machine connectée :

```bash
docker pull iemperor/romulus:1.0.0
docker save iemperor/romulus:1.0.0 | gzip > romulus-1.0.0.tar.gz
# … clé USB …
docker load -i romulus-1.0.0.tar.gz      # sur le serveur isolé
```

### 2. Créer `compose.yaml`

Ce fichier est déjà fourni dans le package.

```yaml
name: romulus

services:
  romulus:
    image: ${ROMULUS_IMAGE:-iemperor/romulus:latest}
    restart: unless-stopped
    ports:
      - "${HTTP_BIND:-0.0.0.0}:${HTTP_PORT:-8080}:8080"
    environment:
      # Adresse des paquets donnée aux clients ; vide = celle par laquelle ils contactent le miroir
      PUBLIC_URL: ${PUBLIC_URL:-}
      APP_SECRET: ${APP_SECRET:-}
      APP_SECRET_FILE: ${APP_SECRET_FILE:-}
    volumes:
      - data:/data
      # Clés USB, en lecture seule. Les bundles sont cherchés jusqu'à deux niveaux de sous-dossiers
      # (/media/<utilisateur>/<CLÉ>/…) ; rslave : une clé branchée après le démarrage est vue aussitôt.
      - type: bind
        source: ${USB_DIR:-/media}
        target: /inbox
        read_only: true
        bind:
          propagation: rslave
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    read_only: true
    tmpfs:
      - /tmp
      - /config:mode=1777
      - /app/var:mode=1777

volumes:
  data:
```

### 3. Le fichier `.env` (facultatif)

```bash
ROMULUS_IMAGE=iemperor/romulus:1.0.0

# Port d'écoute : les postes clients utiliseront http://<serveur>:8080
HTTP_BIND=0.0.0.0
HTTP_PORT=8080

# Adresse publique, seulement derrière un reverse proxy HTTPS (ex. https://romulus.intranet)
PUBLIC_URL=

# Dossier où les clés USB sont montées sur le serveur
USB_DIR=/media
```

### 4. Démarrer

```bash
docker compose up -d
docker compose logs romulus | grep "Code d'installation"
```

Au premier démarrage, Romulus génère ses secrets (voir [Secrets](#secrets)) et affiche un **code
d'installation** à usage unique. Il apparaît aussi avec
`docker compose exec romulus php bin/console app:setup:code`.

### 5. Assistant d'installation

Ouvrez `http://<serveur>:8080`. L'assistant demande :

1. le **code d'installation** ;
2. le **compte administrateur** (12 caractères minimum pour le mot de passe) ;
3. en option, la **clé publique de Rémus** (Rémus › Réglages › Signature des bundles), pour accepter ses
   bundles dès le premier import.

Le code est ensuite détruit et l'assistant se ferme définitivement. D'autres comptes se créent en ligne
de commande : `docker compose exec romulus php bin/console app:user:create <identifiant>`.

---

## Importer un bundle

1. Branchez la clé USB sur le serveur. Elle doit être montée sous `USB_DIR` (`/media` par défaut).
   - Un poste avec bureau la monte tout seul sous `/media/<utilisateur>/<NOM>`.
   - Sur un serveur sans bureau : `sudo mount /dev/sdX1 /media/cle`.
2. Ouvrez **Import** : les bundles de la clé apparaissent avec leur contenu, leur numéro et leur état.
3. **Premier import d'un collecteur :** Romulus affiche l'**empreinte** de sa clé. Vérifiez qu'elle est
   identique à celle de Rémus (Réglages › Signature des bundles) avant de cocher l'approbation.
4. Importez les bundles **dans l'ordre de leurs numéros**. Un bundle « Nouveautés » s'appuie sur les
   précédents : s'il en manque un, Romulus l'indique et n'importe rien.

Sans possibilité de monter une clé sur le serveur, utilisez **Import › Envoyer depuis cet ordinateur**.
L'envoi se fait par morceaux de 16 Mo et reprend là où il s'était arrêté.

Un bundle modifié, signé par un collecteur inconnu ou incomplet est **refusé avant toute écriture**.

---

## Configurer les postes du réseau isolé

La page **Utilisation** de Romulus donne ces réglages avec l'adresse exacte du serveur. Dans les exemples,
remplacez `http://romulus:8080` par cette adresse.

**npm** (et yarn 1, pnpm) :

```bash
npm config set registry http://romulus:8080/npm/
npm config set audit false
```

**pip :**

```bash
pip config set global.index-url http://romulus:8080/pypi/simple/
pip config set global.trusted-host romulus
```

**uv :** `export UV_DEFAULT_INDEX=http://romulus:8080/pypi/simple/` et `export UV_INSECURE_HOST=romulus:8080`.

**Composer :**

```bash
composer config -g repositories.romulus composer http://romulus:8080/composer
composer config -g repositories.packagist false
composer config -g secure-http false
```

**Symfony Flex :** pour que les recettes viennent de Romulus et non de GitHub, ce qui est indispensable
pour `composer create-project symfony/skeleton` hors ligne :

```bash
export SYMFONY_ENDPOINT=http://romulus:8080/composer/flex     # dans ~/.bashrc ou ~/.profile
```

**Docker :** dans `/etc/docker/daemon.json`, ou `~/.config/docker/daemon.json` en rootless, puis redémarrez Docker :

```json
{
  "registry-mirrors": ["http://romulus:8080"],
  "insecure-registries": ["romulus:8080"]
}
```

- Les images Docker Hub se tirent alors sans changer leur nom : `docker pull nginx:1.27`.
- Les images d'autres registres se tirent avec le préfixe du miroir : `docker pull romulus:8080/ghcr.io/owner/app:1.0`.
- Podman et containerd passent par `/etc/containers/registries.conf`, avec un `[[registry.mirror]]` dont
  `location = "romulus:8080"` et `insecure = true`.

Les options `trusted-host`, `secure-http false` et `insecure-registries` ne servent que parce que
Romulus est en HTTP (voir [HTTPS](#https)).

---

## HTTPS

Romulus écoute en HTTP. Pour du HTTPS, placez un reverse proxy devant avec un certificat de votre PKI
interne, et renseignez `PUBLIC_URL`. Exemple avec Caddy :

```
romulus.intranet {
	tls /certs/romulus.crt /certs/romulus.key
	reverse_proxy romulus:8080
}
```

```bash
PUBLIC_URL=https://romulus.intranet
```

Les postes utilisent alors `https://romulus.intranet/...` sans aucune option « insecure ».

## Variables d'environnement

Aucune n'est obligatoire.

| Variable | Défaut | Rôle |
|---|---|---|
| `PUBLIC_URL` | adresse de la requête | Adresse mise dans les liens des paquets (`https://…` derrière un reverse proxy). Mal formée, elle arrête le démarrage |
| `APP_SECRET` / `APP_SECRET_FILE` | généré | Secret des sessions, voir [Secrets](#secrets) |

Côté Compose : `ROMULUS_IMAGE`, `HTTP_BIND`, `HTTP_PORT` et `USB_DIR` (voir l'étape 3).

## Volumes et ports

| Chemin | Contenu |
|---|---|
| `/data` | Base SQLite, paquets (stockage par empreinte : un fichier commun à plusieurs paquets n'est stocké qu'une fois), secrets (`secrets/`), envois du navigateur. **À sauvegarder.** |
| `/inbox` | Point de montage des clés USB (lecture seule) |

Port **8080** (HTTP). L'image tourne sans privilège, avec un système de fichiers en lecture seule. Elle a
besoin de trois `tmpfs` : `/tmp`, `/config` et `/app/var`.

## Secrets

Rien n'est à configurer : `APP_SECRET`, qui signe les sessions, est généré au premier démarrage dans
`/data/secrets/app_secret`. Lors d'une mise à jour, **un secret existant et valide n'est jamais modifié** :

| Situation | Comportement |
|---|---|
| `APP_SECRET` ou `APP_SECRET_FILE` fourni (Docker secret) | utilisé ; invalide (moins de 32 caractères, espaces), le démarrage s'arrête avec une explication |
| Secret conservé dans le volume, valide | inchangé |
| Secret conservé dans le volume, invalide | régénéré, l'ancienne valeur est gardée à côté |

Les clés publiques des collecteurs approuvés sont dans la base, sous **Réglages**. Romulus ne détient
aucune clé secrète : il ne fait que vérifier les signatures.

## Exploitation

### Mise à jour

Copiez le nouveau package **dans le même dossier** que le précédent. Votre `.env` est conservé.

```bash
tar -xf romulus-1.1.0.tar --strip-components=1
sha256sum -c SHA256SUMS
docker load -i romulus-1.1.0.tar.gz
./configure.sh          # ajoute les nouvelles variables et passe à la nouvelle image ; vos réglages ne changent pas
docker compose up -d    # migrations appliquées au démarrage ; secrets, paquets et collecteurs approuvés conservés
```

### Sauvegarde et restauration

```bash
docker compose stop
docker run --rm -v romulus_data:/data -v "$PWD":/backup alpine tar -czf /backup/romulus-data.tar.gz -C /data .
docker compose start
```

L'image `alpine` doit être disponible sur le serveur : chargez-la par clé USB comme Romulus, ou utilisez
toute autre image présente qui contient `tar`.

Restauration sur un serveur neuf, avant le premier `up` :

```bash
docker volume create romulus_data
docker run --rm -v romulus_data:/data -v "$PWD":/backup alpine sh -c "tar -xzf /backup/romulus-data.tar.gz -C /data && chown -R 1000:1000 /data"
docker compose up -d
```

Romulus peut aussi être reconstruit sans sauvegarde : demandez à Rémus un **export complet**, puis
importez-le dans un Romulus neuf.

### Commandes utiles

```bash
docker compose logs -f romulus                                   # journaux (application et imports)
docker compose exec romulus php bin/console app:user:create bob  # autre compte (mot de passe saisi masqué)
docker compose exec romulus php bin/console app:setup:code       # code d'installation (avant le premier compte)
```

## Sécurité

- La clé USB est le **seul lien avec l'extérieur**, et le point à protéger. Romulus n'accepte que des bundles
  signés par une clé approuvée, vérifie la signature avant toute écriture, puis l'empreinte de chaque fichier.
- **Assistant d'installation** protégé par un code à usage unique, lisible seulement sur le serveur. Sans
  ce code, un tiers du réseau ne peut pas s'attribuer le compte administrateur.
- **Administration** protégée par mot de passe : session `HttpOnly` et `SameSite=Strict`, limitation des
  tentatives. Les **registres** sont en lecture seule et accessibles sans authentification sur le réseau
  isolé, comme un dépôt interne classique ; `docker push` est refusé.
- **Conteneur :** utilisateur sans privilège (UID 1000), toutes les capabilities retirées, `no-new-privileges`,
  système de fichiers en lecture seule, clés USB montées en lecture seule.

## Dépannage

| Symptôme | Cause et solution |
|---|---|
| Aucun bundle dans **Import** | Clé hors de `USB_DIR`, ou montée après le démarrage sans `propagation: rslave` : vérifiez `docker compose exec romulus ls /inbox`, sinon redémarrez le conteneur |
| « Collecteur inconnu » | Premier import : comparez l'empreinte avec celle de Rémus et approuvez-le |
| « Signature invalide » | Le bundle a été modifié ou abîmé pendant la copie : réexportez-le depuis Rémus |
| « Partie(s) manquante(s) » | Copiez **toutes** les parties `sas-….tar.001`, `.002`… |
| « dépendent de fichiers d'un bundle précédent » | Importez d'abord les bundles indiqués, ou demandez un export complet à Rémus |
| `npm` affiche « EAI_AGAIN » ou cherche registry.npmjs.org | Registre non configuré sur le poste : `npm config get registry` |
| `composer create-project symfony/skeleton` contacte raw.githubusercontent.com | `SYMFONY_ENDPOINT` absent sur le poste |
| `docker pull` : « server gave HTTP response to HTTPS client » | Ajoutez le serveur à `insecure-registries`, ou passez par HTTPS |
| `le volume /data n'est pas accessible en écriture` | `docker run --rm -v romulus_data:/data alpine chown -R 1000:1000 /data` |
