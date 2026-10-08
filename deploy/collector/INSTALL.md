# Installer Rémus, le collecteur

Le collecteur tourne sur une machine **connectée à Internet**. Il télécharge les paquets et écrit
des bundles signés à transporter sur clé USB vers le miroir.

```bash
./configure.sh                  # crée .env (dossier d'export, port…) ; à relire
docker compose up -d
docker compose logs collector | grep "Code d'installation"
```

Ouvrez l'interface : l'assistant d'installation demande ce code, crée le compte administrateur et
nomme le collecteur. Aucun secret n'est à configurer : ils sont générés au premier démarrage.

L'interface est sur `http://127.0.0.1:8080` (réglez `HTTP_BIND=0.0.0.0` pour y accéder depuis le réseau local).

## Dossier d'export

`EXPORT_DIR` est le dossier où sont écrits les bundles : directement la clé USB montée
(ex. `/media/moi/CLE`) ou un dossier intermédiaire que vous copiez ensuite. Les bundles peuvent aussi
être téléchargés depuis l'interface (Exports › Historique).

Le conteneur écrit avec l'UID 1000 : sur une machine où votre utilisateur n'a pas cet UID, ou en
Docker rootless, rendez le dossier accessible, par exemple `chmod 777 exports`.

Une clé formatée en **FAT32** n'accepte pas de fichier de plus de 4 Go : choisissez alors le
découpage « Parties de 4 Go » au moment de l'export.

## La clé de signature

Le collecteur crée à son premier démarrage une paire de clés Ed25519 (volume `data`, dossier
`identity`). Elle n'est **jamais régénérée automatiquement** : une clé perdue, corrompue ou différente
de celle fournie arrête le démarrage avec une explication, plutôt que de produire des bundles que
les miroirs refuseraient.

**Sauvegardez ce volume.** Pour réinstaller Rémus sur une nouvelle machine en gardant la même clé,
renseignez `REMUS_SIGNING_KEY` (contenu de `identity/secret.key`) ou `REMUS_SIGNING_KEY_FILE` dans
`.env` avant le premier démarrage : la clé est importée dans le volume, puis la variable peut être retirée.

## Mise à jour

```bash
docker load -i …        # ou docker pull
./configure.sh          # nouvelles variables et nouvelle image ; vos réglages et secrets ne changent pas
docker compose up -d
```

## Limites de débit

- Docker Hub limite les téléchargements anonymes : renseignez `DOCKERHUB_USERNAME` et un jeton en
  lecture seule (`DOCKERHUB_TOKEN`) pour les gros volumes.
- `GITHUB_TOKEN` (jeton sans permission) évite les limites de l'API GitHub pour certains paquets Composer.
