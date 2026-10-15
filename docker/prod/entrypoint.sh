#!/bin/sh
# Démarrage de Rémus (collecteur) ou Romulus (miroir) en production.
#
# Aucune variable n'est obligatoire : les secrets sont générés au premier démarrage et conservés dans
# le volume /data. Lors d'une mise à jour de l'image, un secret existant et valide n'est jamais modifié.
#
# Pour chaque secret, ordre de priorité :
#   1. la variable d'environnement (ex. APP_SECRET)
#   2. un fichier désigné par <NOM>_FILE (Docker secrets : APP_SECRET_FILE=/run/secrets/app_secret)
#   3. la valeur conservée dans /data/secrets/
#   4. sinon une valeur générée, puis conservée
# Un secret fourni (1 ou 2) mais invalide arrête le démarrage avec une explication : il n'est jamais remplacé.
set -eu
cd /app

case "$SAS_APP" in
	collector) SAS_NAME=remus ;;
	*) SAS_NAME=romulus ;;
esac

info() { echo "[$SAS_NAME] $*" >&2; }
warn() { echo "[$SAS_NAME] ATTENTION : $*" >&2; }
fail() { echo "[$SAS_NAME] ERREUR : $*" >&2; exit 1; }

# ---------- Utilisateur et droits ----------
# Le conteneur tourne sous SAS_UID (compose « user: ${SAS_UID}:0 ») : 0 en Docker rootless, où le root du
# conteneur est l'utilisateur de l'hôte sans aucun privilège, et l'UID de l'utilisateur sinon. Les dossiers
# montés depuis l'hôte (clé USB, export) lui appartiennent alors.
uid=$(id -u)
if [ "$uid" = 0 ]; then
	# Volume créé par une version qui tournait en UID 1000 : on en reprend la propriété (une seule fois)
	if [ -n "$(find /data -xdev ! -user 0 -print -quit 2>/dev/null)" ]; then
		if chown -R 0:0 /data 2>/dev/null; then
			info "Droits du volume /data repris pour l'UID 0 (Docker rootless)."
		else
			warn "volume /data créé sous un autre UID : ajoutez cap_add CHOWN, DAC_OVERRIDE et FOWNER (voir compose.yaml du package)."
		fi
	fi
fi

# Docker rootless : le root du conteneur correspond à un utilisateur non root de l'hôte
rootless() { awk '$1 == 0 && $2 != 0 { found = 1 } END { exit !found }' /proc/self/uid_map 2>/dev/null; }

if [ "$uid" != 0 ] && rootless; then
	warn "Docker rootless détecté, mais l'application tourne avec l'UID $uid, sans droit sur les dossiers de votre utilisateur (clés USB, export). Réglez SAS_UID=0 dans .env (./configure.sh le détecte), puis docker compose up -d."
fi

# Explique un dossier monté inaccessible : UID propriétaire et réglage à faire
mount_hint() {
	owner=$(stat -c %u "$1" 2>/dev/null || echo '?')
	if [ "$uid" != 0 ] && rootless; then
		echo "Docker rootless : réglez SAS_UID=0 dans .env, puis docker compose up -d."
	elif [ "$owner" != "$uid" ] && [ "$owner" != '?' ] && [ "$owner" != 65534 ]; then
		echo "$1 appartient à l'UID $owner alors que l'application tourne avec l'UID $uid : réglez SAS_UID=$owner dans .env (Docker rootless : 0 ; ./configure.sh le détecte), puis docker compose up -d."
	else
		echo "vérifiez les droits de $1 sur l'hôte."
	fi
}

# ---------- Volume de données ----------
if ! (mkdir -p /data/secrets && touch /data/.write-test && rm -f /data/.write-test) 2>/dev/null; then
	fail "le volume /data n'est pas accessible en écriture : $(mount_hint /data)"
fi
chmod 700 /data/secrets
mkdir -p /data/storage /data/sessions /data/home
[ "$SAS_APP" = mirror ] && mkdir -p /data/uploads
[ "$SAS_APP" = collector ] && mkdir -p /data/identity /data/cache

# Lit <NOM> ou le fichier <NOM>_FILE (variables facultatives, ex. jetons d'API)
file_env() {
	name=$1
	eval "value=\${$name:-}"
	eval "file=\${${name}_FILE:-}"
	if [ -z "$value" ] && [ -n "$file" ]; then
		[ -r "$file" ] || fail "${name}_FILE : fichier $file illisible."
		value=$(cat "$file")
		export "$name=$value"
	fi
}

# Secret valide : au moins $2 caractères visibles, sans espace
secret_valid() {
	[ "${#1}" -ge "$2" ] && printf '%s' "$1" | grep -Eq '^[[:graph:]]+$'
}

secret() {
	name=$1
	min=$2
	store=/data/secrets/$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')
	eval "value=\${$name:-}"
	eval "file=\${${name}_FILE:-}"

	if [ -n "$value" ] || [ -n "$file" ]; then
		if [ -z "$value" ]; then
			[ -r "$file" ] || fail "${name}_FILE : fichier $file illisible."
			value=$(cat "$file")
		fi
		secret_valid "$value" "$min" \
			|| fail "$name fourni mais invalide : au moins $min caractères, sans espace. Corrigez-le ou retirez-le pour qu'il soit généré."
	elif [ -s "$store" ]; then
		value=$(cat "$store")
		if ! secret_valid "$value" "$min"; then
			mv "$store" "$store.invalide-$(date +%Y%m%d%H%M%S)"
			warn "$name conservé dans le volume invalide : régénéré (ancienne valeur gardée à côté)."
			value=
		fi
	fi

	if [ -z "$value" ]; then
		value=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
		(umask 077 && printf '%s' "$value" > "$store")
		info "Secret $name généré et conservé dans le volume."
	fi
	export "$name=$value"
}

# Reprise des versions précédentes : /data/.app_secret devient /data/secrets/app_secret
if [ -s /data/.app_secret ] && [ ! -e /data/secrets/app_secret ]; then
	mv /data/.app_secret /data/secrets/app_secret
	chmod 600 /data/secrets/app_secret
fi

secret APP_SECRET 32

# ---------- Configuration (facultative), vérifiée ----------
url_valid() { printf '%s' "$1" | grep -Eq '^https?://[^/[:space:]]+(/[^[:space:]]*)?$'; }

if [ -n "${PUBLIC_URL:-}" ]; then
	url_valid "$PUBLIC_URL" || fail "PUBLIC_URL invalide : « $PUBLIC_URL » (attendu : https://miroir.exemple ou http://serveur:8080)."
	export PUBLIC_URL="${PUBLIC_URL%/}"
fi

if [ "$SAS_APP" = collector ]; then
	for var in NPM_REGISTRY PYPI_INDEX; do
		eval "value=\${$var:-}"
		if [ -n "$value" ]; then
			url_valid "$value" || fail "$var invalide : « $value »."
			export "$var=${value%/}"
		fi
	done
	file_env GITHUB_TOKEN
	file_env DOCKERHUB_TOKEN
	if [ -n "${DOCKERHUB_USERNAME:-}" ] && [ -z "${DOCKERHUB_TOKEN:-}" ]; then
		warn "DOCKERHUB_USERNAME est renseigné sans DOCKERHUB_TOKEN : accès anonyme à Docker Hub."
	fi
	if ! (touch "$EXPORT_DIR/.write-test" && rm -f "$EXPORT_DIR/.write-test") 2>/dev/null; then
		warn "dossier d'export non accessible en écriture, les exports échoueront : $(mount_hint "$EXPORT_DIR")"
	fi
	file_env REMUS_SIGNING_KEY
elif [ ! -r "$INBOX_DIR" ] || [ ! -x "$INBOX_DIR" ]; then
	warn "dossier des clés USB illisible, seul l'envoi par le navigateur sera possible : $(mount_hint "$INBOX_DIR")"
fi

# ---------- Application ----------
# Cache Symfony (le dossier var/ peut être un tmpfs vide si le conteneur est en lecture seule)
php bin/console cache:warmup --no-interaction -q

info "Migrations de la base…"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration -q

if [ "$SAS_APP" = collector ]; then
	# Clé de signature des bundles : créée au premier démarrage, jamais remplacée ensuite
	php bin/console app:identity:check --no-interaction >&2 || exit 1
	# La clé importée est désormais dans le volume : inutile de la laisser au serveur web
	unset REMUS_SIGNING_KEY REMUS_SIGNING_KEY_FILE
fi

# Premier démarrage : code de l'assistant d'installation (ouvrir l'interface web pour créer le compte)
php bin/console app:setup:code --no-interaction >&2

# Worker des tâches (résolutions, exports, imports) : redémarré s'il s'arrête
(
	while true; do
		php bin/console messenger:consume async --time-limit=3600 --memory-limit=768M --no-interaction -q || sleep 5
	done
) &

exec "$@"
