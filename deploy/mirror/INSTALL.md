# Installer le miroir Sas sur le réseau isolé

Le miroir sert aux postes du réseau isolé les paquets npm, Python, Composer et les images Docker
apportés par clé USB depuis le collecteur. Il n'a besoin d'aucun accès à Internet.

## Prérequis

- Un serveur Linux du réseau isolé avec Docker et Docker Compose.
- De la place disque : le miroir stocke tout ce que le collecteur exporte, sans doublon.

## Installation

Copiez le kit `sas-miroir-<version>.tar` sur le serveur, puis :

```bash
tar -xf sas-miroir-1.0.0.tar && cd sas-miroir-1.0.0
sha256sum -c SHA256SUMS                      # le kit n'a pas été abîmé pendant la copie
docker load -i sas-mirror-1.0.0.tar.gz
cp .env.example .env                         # port, dossier de montage des clés…
docker compose up -d
docker compose exec mirror php bin/console app:user:create admin
```

L'interface est alors sur `http://<serveur>:8080`.

## Importer un bundle

1. Branchez la clé USB sur le serveur. Elle doit être montée sous le dossier `USB_DIR` du `.env`
   (par défaut `/media`, où le bureau monte les clés : `/media/<utilisateur>/<NOM>`).
   Sur un serveur sans bureau : `sudo mount /dev/sdX1 /media/cle`.
2. Ouvrez **Import** : les bundles de la clé apparaissent.
3. Au premier import, le miroir affiche l'**empreinte** de la clé du collecteur. Comparez-la avec
   celle du collecteur (Réglages › Signature des bundles) ; si elles sont identiques, approuvez.
4. Importez les bundles **dans l'ordre de leurs numéros**.

Sans montage possible sur le serveur, les fichiers du bundle peuvent aussi être déposés depuis un
navigateur (Import › Envoyer depuis cet ordinateur), même s'ils pèsent plusieurs Go.

Un bundle modifié, signé par un collecteur inconnu ou incomplet est refusé avant toute écriture.

## Configurer les postes

La page **Utilisation** du miroir donne les commandes exactes pour npm, pip/uv, Composer et Docker,
avec l'adresse du serveur. En résumé :

| Outil | Réglage |
|---|---|
| npm | `npm config set registry http://serveur:8080/npm/` |
| pip | `pip config set global.index-url http://serveur:8080/pypi/simple/` + `trusted-host` |
| Composer | dépôt `composer` `http://serveur:8080/composer` et `packagist.org: false` |
| Docker | `registry-mirrors` et `insecure-registries` dans `/etc/docker/daemon.json` |

## HTTPS

Le miroir écoute en HTTP. Pour du HTTPS, placez un reverse proxy devant (Caddy, nginx, Traefik) avec
le certificat de votre PKI interne, et renseignez `PUBLIC_URL=https://…` dans `.env`. Les options
« trusted-host », « secure-http false » et « insecure-registries » deviennent alors inutiles.

## Exploitation

```bash
docker compose logs -f                         # journaux
docker compose exec mirror php bin/console app:user:create autre-compte
docker compose down && docker compose up -d    # redémarrage
```

Toutes les données (base, paquets, sessions) sont dans le volume `data`. Pour le sauvegarder :

```bash
docker run --rm -v sas-miroir_data:/data -v "$PWD":/backup alpine tar -czf /backup/sas-miroir-data.tar.gz -C /data .
```

Mise à jour : copiez le nouveau kit, `docker load -i …`, changez `SAS_MIRROR_IMAGE` dans `.env`,
puis `docker compose up -d`. Les migrations de la base sont appliquées au démarrage.

### Docker rootless

Le conteneur tourne avec l'UID 1000, qui correspond en rootless à un sous-UID de votre utilisateur.
Le volume `data` est géré par Docker ; seuls les fichiers de la clé doivent être lisibles par tous
(c'est le cas par défaut des clés FAT32/exFAT).
