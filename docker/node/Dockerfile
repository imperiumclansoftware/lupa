ARG NODE_VERSION=24
FROM node:${NODE_VERSION}-alpine

# Même logique que l'image PHP : 0 en Docker rootless, votre UID/GID sinon
ARG UID=0
ARG GID=0

RUN apk add --no-cache git \
 && if [ "$UID" != 0 ]; then \
      deluser --remove-home node \
      && (delgroup node 2>/dev/null || true) \
      && (addgroup -g ${GID} app 2>/dev/null || true) \
      && adduser -D -u ${UID} -G "$(getent group ${GID} | cut -d: -f1)" -h /home/app app; \
    fi \
 && mkdir -p /home/app/.npm /app \
 && chown -R ${UID}:${GID} /home/app /app

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

ENV HOME=/home/app \
    npm_config_cache=/home/app/.npm \
    npm_config_fund=false \
    npm_config_update_notifier=false

USER ${UID}:${GID}
WORKDIR /app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
