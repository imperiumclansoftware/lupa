#!/bin/sh
# Crée ou met à jour un fichier .env à partir de son modèle (.env.example), sans jamais écraser
# une valeur existante et valide :
#   - fichier absent : copie du modèle ;
#   - clé du modèle absente du fichier (nouvelle version) : ajoutée avec sa valeur par défaut ;
#   - clé marquée __GENERATED__ dans le modèle : secret aléatoire, généré seulement s'il est absent,
#     vide ou invalide (moins de 32 caractères, espaces, ou encore « __GENERATED__ ») ;
#   - version de l'image (REMUS_IMAGE, ROMULUS_IMAGE…) : alignée sur le modèle du kit, pour que la mise à jour s'applique.
# Usage : env-sync.sh [modèle] [fichier]     (par défaut : .env.example .env)
set -eu

example=${1:-.env.example}
target=${2:-.env}
[ -r "$example" ] || { echo "Modèle $example introuvable." >&2; exit 1; }

random_secret() {
	if command -v openssl >/dev/null 2>&1; then
		openssl rand -hex 32
	else
		head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
	fi
}

secret_valid() {
	[ "${#1}" -ge 32 ] && [ "$1" != "__GENERATED__" ] && printf '%s' "$1" | grep -Eq '^[[:graph:]]+$'
}

umask 077
created=0
if [ ! -f "$target" ]; then
	cp "$example" "$target"
	created=1
fi

added=''
generated=''
images=''
tmp=$(mktemp)
cp "$target" "$tmp"

# Clés du modèle, dans l'ordre
grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$example" | while IFS= read -r line; do
	key=${line%%=*}
	default=${line#*=}
	current_line=$(grep -E "^$key=" "$tmp" | tail -n 1 || true)

	if [ -z "$current_line" ]; then
		value=$default
		[ "$default" = "__GENERATED__" ] && value=$(random_secret)
		printf '%s=%s\n' "$key" "$value" >> "$tmp"
		echo "+$key" >> "$tmp.log"
	elif printf '%s' "$key" | grep -Eq '^[A-Z]+_IMAGE$' && [ "${current_line#*=}" != "$default" ]; then
		sed -i "s|^$key=.*|$key=$default|" "$tmp"
		echo "~$key=$default" >> "$tmp.log"
	elif [ "$default" = "__GENERATED__" ]; then
		current=${current_line#*=}
		if ! secret_valid "$current"; then
			value=$(random_secret)
			sed -i "s|^$key=.*|$key=$value|" "$tmp"
			echo "*$key" >> "$tmp.log"
		fi
	fi
done

if [ -s "$tmp.log" ]; then
	added=$(grep '^+' "$tmp.log" | cut -c2- | tr '\n' ' ' || true)
	generated=$(grep '^\*' "$tmp.log" | cut -c2- | tr '\n' ' ' || true)
	images=$(grep '^~' "$tmp.log" | cut -c2- | tr '\n' ' ' || true)
fi
rm -f "$tmp.log"
cat "$tmp" > "$target"
rm -f "$tmp"
chmod 600 "$target"

if [ "$created" = 1 ]; then
	echo "$target créé à partir de $example (permissions 600)."
fi
[ -n "$added" ] && echo "$target : nouvelle(s) variable(s) ajoutée(s) : $added"
[ -n "$generated" ] && echo "$target : secret(s) généré(s) : $generated"
[ -n "$images" ] && echo "$target : image mise à jour : $images"
[ "$created" = 0 ] && [ -z "$added" ] && [ -z "$generated" ] && [ -z "$images" ] && echo "$target à jour : aucune modification."
exit 0
