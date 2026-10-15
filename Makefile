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
VERSION ?= 1.1.0
app ?= collector

.DEFAULT_GOAL := help
.PHONY: help init up down restart workers ps logs sh setup-code user console composer npm ng test test-back test-front build-front images push remus-kit romulus-kit kits reset

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

test: test-back test-front build-front ## Lance tous les tests

test-back: ## Tests PHPUnit du collecteur et du miroir
	$(DC) run --rm --no-deps -T collector php bin/phpunit
	$(DC) run --rm --no-deps -T mirror php bin/phpunit

test-front: ## Tests unitaires du front (Node, sans navigateur)
	$(DC) exec -T node-collector npm run test:unit

build-front: ## Compile les deux fronts (vérification des types et des gabarits)
	$(DC) exec -T node-collector sh -c 'npx ng build collector && npx ng build mirror'

reset: ## Supprime conteneurs, volumes, bases et stockages de développement
	$(DC) down -v --remove-orphans
	rm -rf collector/var/data collector/var/storage collector/var/identity mirror/var/data mirror/var/storage mirror/var/uploads usb/*

# ---------- Production ----------
# Images publiées sur Docker Hub : iemperor/remus (collecteur) et iemperor/romulus (miroir)
DOCKERHUB_USER ?= iemperor
REMUS_IMAGE := docker.io/$(DOCKERHUB_USER)/remus
ROMULUS_IMAGE := docker.io/$(DOCKERHUB_USER)/romulus

images: ## Construit les deux images (make images VERSION=1.0.0)
	docker build -f docker/prod/Dockerfile --target collector --build-arg APP_VERSION=$(VERSION) -t $(REMUS_IMAGE):$(VERSION) -t $(REMUS_IMAGE):latest .
	docker build -f docker/prod/Dockerfile --target mirror --build-arg APP_VERSION=$(VERSION) -t $(ROMULUS_IMAGE):$(VERSION) -t $(ROMULUS_IMAGE):latest .

push: images ## Publie les deux images sur Docker Hub (après « docker login »)
	docker push $(REMUS_IMAGE):$(VERSION)
	docker push $(REMUS_IMAGE):latest
	docker push $(ROMULUS_IMAGE):$(VERSION)
	docker push $(ROMULUS_IMAGE):latest

remus-kit: ## Package de Rémus : dist/remus-VERSION.tar (compose, .env, configure.sh, README)
	rm -rf dist/remus-$(VERSION) && mkdir -p dist/remus-$(VERSION)/exports
	cp deploy/remus/compose.yaml deploy/remus/.env.example deploy/remus/README.md dist/remus-$(VERSION)/
	cp scripts/env-sync.sh dist/remus-$(VERSION)/configure.sh
	sed -i 's|^REMUS_IMAGE=.*|REMUS_IMAGE=$(REMUS_IMAGE):$(VERSION)|' dist/remus-$(VERSION)/.env.example
	tar -C dist -cf dist/remus-$(VERSION).tar remus-$(VERSION)
	@echo "Package prêt : dist/remus-$(VERSION).tar (l'image se télécharge depuis Docker Hub)."

romulus-kit: ## Package de Romulus, image comprise (réseau isolé) : dist/romulus-VERSION.tar
	@test -n "$$(docker images -q $(ROMULUS_IMAGE):$(VERSION))" || $(MAKE) images VERSION=$(VERSION)
	rm -rf dist/romulus-$(VERSION) && mkdir -p dist/romulus-$(VERSION)
	docker save $(ROMULUS_IMAGE):$(VERSION) | gzip > dist/romulus-$(VERSION)/romulus-$(VERSION).tar.gz
	cp deploy/romulus/compose.yaml deploy/romulus/.env.example deploy/romulus/README.md dist/romulus-$(VERSION)/
	cp scripts/env-sync.sh dist/romulus-$(VERSION)/configure.sh
	sed -i 's|^ROMULUS_IMAGE=.*|ROMULUS_IMAGE=$(ROMULUS_IMAGE):$(VERSION)|' dist/romulus-$(VERSION)/.env.example
	cd dist/romulus-$(VERSION) && sha256sum romulus-$(VERSION).tar.gz compose.yaml configure.sh README.md .env.example > SHA256SUMS
	tar -C dist -cf dist/romulus-$(VERSION).tar romulus-$(VERSION)
	@echo "Package prêt : dist/romulus-$(VERSION).tar ($$(du -h dist/romulus-$(VERSION).tar | cut -f1)), à copier sur la clé USB."

kits: remus-kit romulus-kit ## Les deux packages
