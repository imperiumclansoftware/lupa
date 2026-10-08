import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { CopyButton, EcosystemId, Icon, Eco } from '@sas/shared';
import { mirrorBase } from '../clients';

interface Snippet {
  title: string;
  code: string;
  hint?: string;
}

@Component({
  imports: [Eco, CopyButton, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Utilisation</h1>
          <p>Configuration des postes du réseau isolé. Les adresses ci-dessous utilisent <code>{{ m.base }}</code>, l’adresse par laquelle vous consultez ce miroir.</p>
        </div>
      </header>

      @if (m.http) {
        <div class="alert alert-info"><sas-icon name="lock" />Le miroir est servi en HTTP : chaque outil doit l’accepter explicitement (options incluses ci-dessous). Derrière un reverse proxy HTTPS, ces options deviennent inutiles.</div>
      }

      <div class="tabs" role="tablist" aria-label="Outil">
        @for (t of tabs; track t.id) {
          <button type="button" role="tab" [attr.aria-selected]="tab() === t.id" (click)="tab.set(t.id)">
            <sas-eco [ecosystem]="t.id" [size]="22" />{{ t.label }}
          </button>
        }
      </div>

      <section class="stack">
        @for (s of snippets(); track s.title) {
          <article class="card card-body snippet">
            <div class="row head"><h2>{{ s.title }}</h2><sas-copy [text]="s.code" label="Copier" /></div>
            <pre class="kbd-block">{{ s.code }}</pre>
            @if (s.hint) { <p class="subtle">{{ s.hint }}</p> }
          </article>
        }
      </section>
    </div>
  `,
  styles: `
    .snippet { display: grid; gap: 10px; }
    .head { justify-content: space-between; }
    .kbd-block { padding-right: 16px; }
  `,
})
export default class Usage {
  protected readonly m = mirrorBase();
  protected readonly tabs: { id: EcosystemId; label: string }[] = [
    { id: 'npm', label: 'npm' },
    { id: 'pypi', label: 'pip / uv' },
    { id: 'composer', label: 'Composer' },
    { id: 'docker', label: 'Docker' },
  ];
  protected readonly tab = signal<EcosystemId>('npm');
  protected readonly snippets = computed<Snippet[]>(() => {
    const { base, host, http } = this.m;
    switch (this.tab()) {
      case 'npm':
        return [
          {
            title: 'Configuration de l’utilisateur',
            code: `npm config set registry ${base}/npm/\nnpm config set audit false`,
            hint: 'L’audit de sécurité interroge npmjs.com : sans Internet il est inutile. Fonctionne aussi pour yarn (1.x) et pnpm, qui lisent la même configuration.',
          },
          { title: 'Ou par projet : .npmrc', code: `registry=${base}/npm/\naudit=false` },
          {
            title: 'Projet existant',
            code: `rm -rf node_modules\nnpm ci`,
            hint: 'Un package-lock.json créé sur Internet contient les adresses de registry.npmjs.org : npm les remplace automatiquement par le registre configuré. Collectez ce package-lock.json dans une liste pour que toutes ses versions soient présentes.',
          },
        ];
      case 'pypi':
        return [
          {
            title: 'pip : configuration de l’utilisateur',
            code: `pip config set global.index-url ${base}/pypi/simple/${http ? `\npip config set global.trusted-host ${host.split(':')[0]}` : ''}`,
          },
          {
            title: 'pip : fichier pip.conf (Linux ~/.config/pip/pip.conf, Windows %APPDATA%\\pip\\pip.ini)',
            code: `[global]\nindex-url = ${base}/pypi/simple/${http ? `\ntrusted-host = ${host.split(':')[0]}` : ''}`,
          },
          {
            title: 'uv',
            code: `export UV_DEFAULT_INDEX=${base}/pypi/simple/${http ? `\nexport UV_INSECURE_HOST=${host}` : ''}`,
            hint: 'Ou dans pyproject.toml : [[tool.uv.index]] url = "…" default = true.',
          },
          {
            title: 'Ponctuellement',
            code: `pip install --index-url ${base}/pypi/simple/${http ? ` --trusted-host ${host.split(':')[0]}` : ''} requests`,
          },
        ];
      case 'composer':
        return [
          {
            title: 'Configuration globale',
            code: `composer config -g repositories.romulus composer ${base}/composer\ncomposer config -g repositories.packagist false${http ? '\ncomposer config -g secure-http false' : ''}`,
            hint: 'Packagist est désactivé : sans Internet, Composer attendrait sinon chaque requête jusqu’à expiration.',
          },
          {
            title: 'Symfony : recettes Flex',
            code: `export SYMFONY_ENDPOINT=${base}/composer/flex`,
            hint: 'À ajouter dans ~/.bashrc ou ~/.profile (sous Windows : variable d’environnement utilisateur). Flex prend alors ses recettes dans Romulus au lieu de GitHub : composer create-project symfony/skeleton et composer require fonctionnent hors ligne.',
          },
          {
            title: 'Ou dans le composer.json du projet',
            code: JSON.stringify(
              { repositories: [{ type: 'composer', url: `${base}/composer` }, { 'packagist.org': false }], ...(http ? { config: { 'secure-http': false } } : {}) },
              null,
              4,
            ),
          },
          {
            title: 'Projet existant (composer.lock)',
            code: `composer install`,
            hint: 'Les archives sont téléchargées depuis le miroir grâce au dépôt configuré. Collectez ce composer.lock dans une liste pour que ses versions soient présentes.',
          },
        ];
      case 'docker':
        return [
          {
            title: 'Démon Docker : /etc/docker/daemon.json',
            code: JSON.stringify({ 'registry-mirrors': [base], ...(http ? { 'insecure-registries': [host] } : {}) }, null, 2),
            hint: 'Puis « sudo systemctl restart docker ». Les images Docker Hub se tirent alors sans changer leur nom : docker pull nginx:1.27. En Docker rootless : ~/.config/docker/daemon.json.',
          },
          {
            title: 'Avec le nom du miroir',
            code: `docker pull ${host}/library/nginx:1.27\ndocker pull ${host}/nginx:1.27\ndocker pull ${host}/ghcr.io/owner/app:1.0`,
            hint: 'Images d’autres registres (ghcr.io, quay.io…) : préfixez par l’adresse du miroir. Pour un miroir en HTTP, « insecure-registries » reste nécessaire.',
          },
          {
            title: 'Podman, containerd, Kubernetes : /etc/containers/registries.conf',
            code: `[[registry]]\nprefix = "docker.io"\nlocation = "docker.io"\n\n[[registry.mirror]]\nlocation = "${host}"${http ? '\ninsecure = true' : ''}`,
          },
        ];
    }
  });
}
