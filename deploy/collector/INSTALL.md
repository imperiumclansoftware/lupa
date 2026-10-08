# Installer Rémus, le collecteur

Le collecteur tourne sur une machine **connectée à Internet**. Il télécharge les paquets et écrit
des bundles signés à transporter sur clé USB vers le miroir.

```bash
cp .env.example .env            # dossier d'export, port…
docker compose up -d
docker compose exec collector php bin/console app:user:create admin
```

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
`identity`). **Sauvegardez ce volume** : sans la clé secrète, les miroirs refuseront les bundles d'un
collecteur réinstallé jusqu'à ce que vous approuviez sa nouvelle clé.

## Limites de débit

- Docker Hub limite les téléchargements anonymes : renseignez `DOCKERHUB_USERNAME` et un jeton en
  lecture seule (`DOCKERHUB_TOKEN`) pour les gros volumes.
- `GITHUB_TOKEN` (jeton sans permission) évite les limites de l'API GitHub pour certains paquets Composer.
