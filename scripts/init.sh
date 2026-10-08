#!/usr/bin/env bash
# Prépare l'environnement de développement : .env créé ou complété (nouvelles variables, secrets
# manquants ou invalides) sans toucher aux valeurs existantes. Lancé par chaque « make up ».
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p usb
new=0
[[ -f .env ]] || new=1
./scripts/env-sync.sh .env.example .env

# Premier passage : utilisateur des conteneurs selon le mode de Docker
if [[ "$new" = 1 ]]; then
  if docker info --format '{{.SecurityOptions}}' 2>/dev/null | grep -q rootless; then
    APP_UID=0; APP_GID=0; echo "Docker rootless détecté."
  else
    APP_UID=$(id -u); APP_GID=$(id -g)
  fi
  sed -i -e "s/^APP_UID=.*/APP_UID=${APP_UID}/" -e "s/^APP_GID=.*/APP_GID=${APP_GID}/" .env
fi
