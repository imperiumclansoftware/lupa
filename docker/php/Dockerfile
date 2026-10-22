ARG PHP_VERSION=8.4.27
FROM php:${PHP_VERSION}-fpm-alpine

# UID/GID de l'utilisateur qui exécute PHP dans le conteneur.
#  - Docker rootless : 0 (le root du conteneur = votre utilisateur sur l'hôte, sans privilège)
#  - Docker classique : votre UID/GID (ex. 1000), un utilisateur "app" est créé
ARG UID=0
ARG GID=0

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# nodejs/npm, python3/pip et composer servent au collecteur pour résoudre les dépendances
# (même image pour le collecteur et le miroir en développement)
RUN apk add --no-cache git unzip icu-data-full bash nodejs npm python3 py3-pip xz zstd bzip2 gpgv \
 && install-php-extensions intl opcache zip apcu \
 && python3 -m venv /opt/pip && /opt/pip/bin/pip install --no-cache-dir --quiet --upgrade pip \
 && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && if [ "$UID" != 0 ]; then \
      (addgroup -g ${GID} app 2>/dev/null || true) \
      && adduser -D -u ${UID} -G "$(getent group ${GID} | cut -d: -f1)" -h /home/app app; \
    fi \
 && mkdir -p /home/app/.composer /home/app/.npm /home/app/.cache /srv/sas \
 && chown -R ${UID}:${GID} /home/app /srv/sas

COPY php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
RUN if [ "$UID" = 0 ]; then sed -i 's/^user =$/user = root/; s/^group =$/group = root/' /usr/local/etc/php-fpm.d/zz-app.conf; fi

ENV HOME=/home/app \
    COMPOSER_HOME=/home/app/.composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    npm_config_cache=/home/app/.npm \
    npm_config_update_notifier=false \
    npm_config_fund=false \
    PIP_DISABLE_PIP_VERSION_CHECK=1

USER ${UID}:${GID}
WORKDIR /srv/sas
EXPOSE 9000
CMD ["php-fpm", "-F", "-R"]
