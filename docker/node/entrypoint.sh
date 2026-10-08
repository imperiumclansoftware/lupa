#!/bin/sh
# Lance « ng serve » pour l'application Angular indiquée par NG_PROJECT (collector ou mirror)
set -e

if [ "$#" -gt 0 ]; then
  exec "$@"
fi

if [ ! -f package.json ]; then
  echo "Aucun projet Angular dans ./frontend."
  exec sleep infinity
fi

# Les deux serveurs partagent node_modules : un seul fait l'installation
if [ ! -d node_modules ]; then
  if [ "$NG_PROJECT" = collector ]; then npm ci; else
    echo "Attente de l'installation des dépendances par le conteneur du collecteur…"
    while [ ! -f node_modules/.package-lock.json ]; do sleep 3; done
  fi
fi

exec npx ng serve "$NG_PROJECT" --host 0.0.0.0 --port "$NG_PORT" --allowed-hosts="$NG_HOST" ${NG_POLL:+--poll="$NG_POLL"}
