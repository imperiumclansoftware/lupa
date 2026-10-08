#!/bin/sh
# Démarrage : secret, base de données (migrations), puis worker des tâches de fond et serveur web.
set -eu
cd /app

info() { echo "[sas-$SAS_APP] $*" >&2; }

mkdir -p /data/storage /data/sessions /data/home
[ "$SAS_APP" = mirror ] && mkdir -p /data/uploads
[ "$SAS_APP" = collector ] && mkdir -p /data/identity /data/cache

# Secret Symfony : fourni par l'environnement, sinon généré une fois et conservé dans le volume
if [ -z "${APP_SECRET:-}" ]; then
	[ -s /data/.app_secret ] || (umask 077 && head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > /data/.app_secret)
	APP_SECRET=$(cat /data/.app_secret)
	export APP_SECRET
fi

# Cache Symfony (le dossier var/ peut être un tmpfs vide si le conteneur est en lecture seule)
php bin/console cache:warmup --no-interaction -q

info "Migrations de la base…"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration -q

if ! php -r '$db = new PDO("sqlite:/data/app.db"); exit((int) $db->query("SELECT COUNT(*) FROM \"user\"")->fetchColumn() > 0 ? 0 : 1);'; then
	info "Aucun compte. Créez-en un : docker compose exec $SAS_APP php bin/console app:user:create admin"
fi

# Worker des tâches (résolutions, exports, imports) : redémarré s'il s'arrête
(
	while true; do
		php bin/console messenger:consume async --time-limit=3600 --memory-limit=768M --no-interaction -q || sleep 5
	done
) &

exec "$@"
