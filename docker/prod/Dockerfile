# syntax=docker/dockerfile:1
#
# Images de production de Sas, construites depuis la racine du projet :
#   docker build -f docker/prod/Dockerfile --target collector -t iemperor/remus .
#   docker build -f docker/prod/Dockerfile --target mirror    -t iemperor/romulus .
# (ou : make images VERSION=1.0.0)
# Chaque image contient le front Angular, l'API Symfony et son worker (FrankenPHP = Caddy + PHP).

# Versions figées : une mise à jour de sécurité se fait ici, puis on reconstruit (make images)
ARG FRANKENPHP_VERSION=1.13.2
ARG PHP_VERSION=8.4.27
ARG NODE_VERSION=24

# ---------- 1. Fronts Angular (fichiers statiques) ----------
FROM node:${NODE_VERSION}-alpine AS front
WORKDIR /front
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npx ng build collector --configuration production \
 && npx ng build mirror --configuration production

# ---------- 2. Socle PHP ----------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine AS base
# apk upgrade : correctifs Alpine publiés depuis la construction de l'image de base
RUN apk upgrade --no-cache \
 && apk add --no-cache icu-data-full libcap \
 && install-php-extensions intl opcache zip apcu pdo_sqlite \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini $PHP_INI_DIR/conf.d/zz-sas.ini

# ---------- 3. API Symfony (dépendances de production, bibliothèque partagée recopiée dans vendor/) ----------
FROM base AS composer-base
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MIRROR_PATH_REPOS=1
COPY shared/ /src/shared/

FROM composer-base AS api-collector
WORKDIR /src/app
COPY collector/composer.json collector/composer.lock collector/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY collector/ ./
RUN rm -rf var tests .env.local .env.*.local phpunit.dist.xml \
 && composer dump-autoload --classmap-authoritative --no-dev \
 && composer dump-env prod \
 && APP_SECRET=build php bin/console cache:warmup

FROM composer-base AS api-mirror
WORKDIR /src/app
COPY mirror/composer.json mirror/composer.lock mirror/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction
COPY mirror/ ./
RUN rm -rf var tests .env.local .env.*.local phpunit.dist.xml \
 && composer dump-autoload --classmap-authoritative --no-dev \
 && composer dump-env prod \
 && APP_SECRET=build php bin/console cache:warmup

# ---------- Réglages communs aux deux images ----------
FROM base AS runtime
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    DATABASE_URL=sqlite:////data/app.db \
    STORAGE_DIR=/data/storage \
    SESSION_DIR=/data/sessions \
    SETUP_TOKEN_FILE=/data/setup.code \
    TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR,private_ranges \
    XDG_DATA_HOME=/data/caddy \
    XDG_CONFIG_HOME=/config
COPY docker/prod/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/prod/entrypoint.sh /usr/local/bin/sas-entrypoint
RUN adduser -D -H -h /data/home -u 1000 app \
 && setcap -r /usr/local/bin/frankenphp
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=4 \
  CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1
ENTRYPOINT ["sas-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------- 4a. Collecteur : outils de résolution (npm, pip, composer) ----------
FROM runtime AS collector
ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="Rémus" \
      org.opencontainers.image.description="Rémus, collecteur Lupa : télécharge paquets npm, Python, Composer, Debian, RPM, Ansible, Terraform et images Docker et prépare des bundles signés pour Romulus, le miroir hors ligne." \
      org.opencontainers.image.url="https://hub.docker.com/r/iemperor/remus" \
      org.opencontainers.image.version="${APP_VERSION}"
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache nodejs npm python3 git unzip xz zstd bzip2 gpgv \
 && python3 -m venv /opt/pip && /opt/pip/bin/pip install --no-cache-dir --quiet --upgrade pip
COPY --from=front /front/dist/collector/browser /srv/front
ENV SAS_APP=collector \
    EXPORT_DIR=/exports \
    IDENTITY_DIR=/data/identity \
    PIP_BIN=/opt/pip/bin/pip \
    npm_config_cache=/data/cache/npm \
    npm_config_update_notifier=false \
    COMPOSER_HOME=/data/cache/composer \
    PIP_CACHE_DIR=/data/cache/pip \
    SYSTEM_CACHE_DIR=/data/cache/system
COPY --from=api-collector /src/app /app
# Dossiers accessibles au groupe 0 : le conteneur peut tourner sous l'UID de l'hôte (SAS_UID, 0 en Docker rootless)
RUN mkdir -p /data /exports /config/caddy /app/var/log \
 && chown -R app:0 /data /exports /config /app/var \
 && chmod -R g=u /data /exports /config /app/var
USER app
VOLUME ["/data", "/exports"]

# ---------- 4b. Miroir : sans aucun outil réseau, il ne fait que servir ----------
FROM runtime AS mirror
ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="Romulus" \
      org.opencontainers.image.description="Romulus, miroir hors ligne Lupa : importe les bundles signés de Rémus et sert les dépôts npm, PyPI, Composer, Docker, apt, dnf, Ansible Galaxy et Terraform sur un réseau isolé." \
      org.opencontainers.image.url="https://hub.docker.com/r/iemperor/romulus" \
      org.opencontainers.image.version="${APP_VERSION}"
COPY --from=front /front/dist/mirror/browser /srv/front
ENV SAS_APP=mirror \
    INBOX_DIR=/inbox \
    UPLOAD_DIR=/data/uploads \
    MIRROR_KEY_DIR=/data/identity
COPY --from=api-mirror /src/app /app
# Dossiers accessibles au groupe 0 : le conteneur peut tourner sous l'UID de l'hôte (SAS_UID, 0 en Docker rootless)
RUN mkdir -p /data /inbox /config/caddy /app/var/log \
 && chown -R app:0 /data /config /app/var \
 && chmod -R g=u /data /config /app/var
USER app
VOLUME ["/data"]
