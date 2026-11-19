#!/bin/sh
# Génère les PDF de docs/legal à partir des sources HTML (docs/legal/src), avec WeasyPrint en conteneur.
# Usage : make docs
set -eu
cd "$(dirname "$0")/.."
docker build -q -t sas-docs:dev docker/docs >/dev/null
# Docker classique : fichiers créés sous votre UID ; rootless : le root du conteneur est déjà votre utilisateur
user=""
docker info --format '{{.SecurityOptions}}' 2>/dev/null | grep -q rootless || user="-u $(id -u):$(id -g)"
for src in docs/legal/src/*.html; do
	name=$(basename "$src" .html)
	docker run --rm $user -v "$PWD/docs/legal:/docs" sas-docs:dev "src/$name.html" "$name.pdf"
	echo "docs/legal/$name.pdf"
done
