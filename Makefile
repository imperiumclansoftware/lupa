# Docker rootless (comme Constellation) : utilisé d'office s'il existe, quel que soit le contexte actif
# (Docker Desktop arrêté, etc.). Forcer un autre contexte : make up DOCKER_CONTEXT=default
ifeq ($(origin DOCKER_CONTEXT),undefined)
  ifneq ($(shell docker context inspect rootless >/dev/null 2>&1 && echo yes),)
    DOCKER_CONTEXT := rootless
  endif
endif
ifdef DOCKER_CONTEXT
  export DOCKER_CONTEXT
endif

DC := docker compose
VERSION ?= 1.0.0
app ?= collector

.DEFAULT_GOAL := help
.PHONY: help init up down restart workers ps logs sh setup-code user console composer npm ng test test-back test-front build-front images mirror-kit collector-kit reset

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS=":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

# ---------- Développement ----------

init: ## Génère .env (secrets aléatoires) et le dossier ./usb qui simule la clé
	@./scripts/init.sh

up: init ## Démarre l'environnement de développement
	$(DC) up -d --build
	@$(DC) exec -T collector sh -c 'mkdir -p var/data && php bin/console doctrine:migrations:migrate -n -q'
	@$(DC) exec -T mirror sh -c 'mkdir -p var/data && php bin/console doctrine:migrations:migrate -n -q'
	@. ./.env; echo "Collecteur : http://$$COLLECTOR_DOMAIN:$$HTTP_PORT | Miroir : http://$$MIRROR_DOMAIN:$$HTTP_PORT"

down: ## Arrête l'environnement
	$(DC) down

restart: ## Redémarre (prend en compte les changements de .env)
	$(DC) up -d --force-recreate

workers: ## Redémarre les workers (à faire après une modification du code PHP des tâches)
	$(DC) restart collector-worker mirror-worker

ps: ## État des services
	$(DC) ps

logs: ## Logs en continu (make logs s=mirror-worker)
	$(DC) logs -f $(s)

sh: ## Shell PHP (make sh app=mirror)
	$(DC) exec $(app) bash

setup-code: ## Code de l'assistant d'installation, tant qu'aucun compte n'existe (make setup-code app=mirror)
	$(DC) exec $(app) php bin/console app:setup:code

user: ## Crée un compte (make user app=collector name=admin)
	$(DC) exec $(app) php bin/console app:user:create $(or $(name),admin)

console: ## bin/console (make console app=mirror c="debug:router")
	$(DC) exec $(app) php bin/console $(c)

composer: ## Composer (make composer app=mirror c="require foo/bar") ; passe par le collecteur, seul à avoir Internet
	$(DC) exec -w /srv/sas/$(app) collector composer $(c)

npm: ## npm du front (make npm c="install foo")
	$(DC) exec node-collector npm $(c)

ng: ## Angular CLI (make ng c="generate component pages/foo --project mirror")
	$(DC) exec node-collector npx ng $(c)

test: test-back build-front ## Lance tous les tests

test-back: ## Tests PHPUnit du collecteur et du miroir
	$(DC) run --rm --no-deps -T collector php bin/phpunit
	$(DC) run --rm --no-deps -T mirror php bin/phpunit

build-front: ## Compile les deux fronts (vérification des types et des gabarits)
	$(DC) exec -T node-collector sh -c 'npx ng build collector && npx ng build mirror'

reset: ## Supprime conteneurs, volumes, bases et stockages de développement
	$(DC) down -v --remove-orphans
	rm -rf collector/var/data collector/var/storage collector/var/identity mirror/var/data mirror/var/storage mirror/var/uploads usb/*

# ---------- Production ----------

images: ## Construit les images de production (make images VERSION=1.0.0)
	docker build -f docker/prod/Dockerfile --target collector --build-arg APP_VERSION=$(VERSION) -t sas-collector:$(VERSION) -t sas-collector:latest .
	docker build -f docker/prod/Dockerfile --target mirror --build-arg APP_VERSION=$(VERSION) -t sas-mirror:$(VERSION) -t sas-mirror:latest .

mirror-kit: ## Kit d'installation du miroir à copier sur clé USB : dist/sas-miroir-VERSION.tar
	@test -n "$$(docker images -q sas-mirror:$(VERSION))" || $(MAKE) images VERSION=$(VERSION)
	rm -rf dist/sas-miroir-$(VERSION) && mkdir -p dist/sas-miroir-$(VERSION)
	docker save sas-mirror:$(VERSION) sas-mirror:latest | gzip > dist/sas-miroir-$(VERSION)/sas-mirror-$(VERSION).tar.gz
	cp deploy/mirror/compose.yaml deploy/mirror/.env.example deploy/mirror/INSTALL.md dist/sas-miroir-$(VERSION)/
	cp scripts/env-sync.sh dist/sas-miroir-$(VERSION)/configure.sh
	sed -i 's/^SAS_MIRROR_IMAGE=.*/SAS_MIRROR_IMAGE=sas-mirror:$(VERSION)/' dist/sas-miroir-$(VERSION)/.env.example
	cd dist/sas-miroir-$(VERSION) && sha256sum * > SHA256SUMS
	tar -C dist -cf dist/sas-miroir-$(VERSION).tar sas-miroir-$(VERSION)
	@echo "Kit prêt : dist/sas-miroir-$(VERSION).tar ($$(du -h dist/sas-miroir-$(VERSION).tar | cut -f1)). Voir INSTALL.md à l'intérieur."

collector-kit: ## Dossier de déploiement du collecteur : dist/sas-collecteur-VERSION
	@test -n "$$(docker images -q sas-collector:$(VERSION))" || $(MAKE) images VERSION=$(VERSION)
	rm -rf dist/sas-collecteur-$(VERSION) && mkdir -p dist/sas-collecteur-$(VERSION)/exports
	cp deploy/collector/compose.yaml deploy/collector/.env.example deploy/collector/INSTALL.md dist/sas-collecteur-$(VERSION)/
	cp scripts/env-sync.sh dist/sas-collecteur-$(VERSION)/configure.sh
	sed -i 's/^SAS_COLLECTOR_IMAGE=.*/SAS_COLLECTOR_IMAGE=sas-collector:$(VERSION)/' dist/sas-collecteur-$(VERSION)/.env.example
	@echo "Prêt : dist/sas-collecteur-$(VERSION) (./configure.sh && docker compose up -d)"
