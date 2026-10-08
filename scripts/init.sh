#!/usr/bin/env bash
# Génère le fichier .env avec des secrets aléatoires (jamais versionné)
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p usb

if [[ -f .env ]]; then
  exit 0
fi

# Docker rootless : le root du conteneur est mappé sur votre utilisateur hôte
if docker info --format '{{.SecurityOptions}}' 2>/dev/null | grep -q rootless; then
  APP_UID=0; APP_GID=0; echo "Docker rootless détecté."
else
  APP_UID=$(id -u); APP_GID=$(id -g)
fi

umask 077
cp .env.example .env
sed -i \
  -e "s/^COLLECTOR_SECRET=.*/COLLECTOR_SECRET=$(openssl rand -hex 32)/" \
  -e "s/^MIRROR_SECRET=.*/MIRROR_SECRET=$(openssl rand -hex 32)/" \
  -e "s/^APP_UID=.*/APP_UID=${APP_UID}/" \
  -e "s/^APP_GID=.*/APP_GID=${APP_GID}/" \
  .env
chmod 600 .env
echo ".env généré avec des secrets aléatoires (permissions 600)."
