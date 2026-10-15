import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { CopyButton, EcosystemId, Icon, Eco } from '@sas/shared';
import { mirrorBase } from '../clients';
import { MirrorApi, SystemDistribution } from '../api';

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

      @if (tab() === 'deb' || tab() === 'rpm') {
        @if (systemDistributions().length === 0) {
          <div class="card empty"><sas-icon name="package" [size]="28" /><h3>Aucune distribution importée</h3>
            <p>Créez dans Rémus une liste {{ tab() === 'deb' ? 'Debian' : 'RPM' }}, exportez-la puis importez le bundle.</p></div>
        } @else {
          <div class="field dist">
            <label for="dist">Distribution</label>
            <select class="select" id="dist" [value]="currentDistribution()?.id" (change)="distribution.set($any($event.target).value)">
              @for (d of systemDistributions(); track d.id) { <option [value]="d.id">{{ d.label }} · {{ d.packages }} paquets · {{ d.architectures.join(', ') }}</option> }
            </select>
          </div>
        }
      }

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
    .dist { max-width: 520px; }
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
    { id: 'galaxy', label: 'Ansible Galaxy' },
    { id: 'terraform', label: 'Terraform' },
    { id: 'deb', label: 'Debian (apt)' },
    { id: 'rpm', label: 'RPM (dnf, yum)' },
  ];
  protected readonly tab = signal<EcosystemId>('npm');
  private readonly api = inject(MirrorApi);
  protected readonly distributions = signal<SystemDistribution[]>([]);
  protected readonly fingerprint = signal('');
  protected readonly distribution = signal('');
  protected readonly systemDistributions = computed(() => this.distributions().filter((d) => d.ecosystem === this.tab()));
  protected readonly currentDistribution = computed(() => this.systemDistributions().find((d) => d.id === this.distribution()) ?? this.systemDistributions().at(0) ?? null);

  constructor() {
    this.api.distributions().then((r) => {
      this.distributions.set(r.items);
      this.fingerprint.set(r.keyFingerprint);
    }).catch(() => undefined);
  }

  protected readonly snippets = computed<Snippet[]>(() => {
    const { base, host, http } = this.m;
    switch (this.tab()) {
      case 'galaxy':
        return [
          {
            title: 'ansible.cfg (projet) ou ~/.ansible.cfg',
            code: `[galaxy]\nserver_list = romulus\n\n[galaxy_server.romulus]\nurl = ${base}/galaxy/`,
            hint: 'Ponctuellement : ansible-galaxy collection install -s ' + base + '/galaxy/ community.general',
          },
          {
            title: 'Collections',
            code: `ansible-galaxy collection install community.general ansible.posix\nansible-galaxy collection install -r requirements.yml`,
            hint: 'Les dépendances déclarées par les collections sont collectées par Rémus ; l’empreinte sha256 de chaque archive est vérifiée par ansible-galaxy.',
          },
          {
            title: 'Rôles : requirements.yml',
            code: `roles:\n  - src: ${base}/galaxy/roles/geerlingguy.docker-7.4.1.tar.gz\n    name: geerlingguy.docker`,
            hint: 'Les rôles s’installent par l’adresse de leur archive (ansible-galaxy role install -r requirements.yml). Les adresses exactes figurent dans Paquets.',
          },
        ];
      case 'terraform':
        return [
          {
            title: http ? 'Providers : miroir local (miroir en HTTP)' : 'Providers : ~/.terraformrc',
            code: http
              ? `mkdir -p ~/.terraform.d/plugins\ncurl -s "${base}/terraform/providers.tar?platform=linux_amd64" | tar -x -C ~/.terraform.d/plugins`
              : `provider_installation {\n  network_mirror {\n    url = "${base}/terraform/providers/"\n  }\n}`,
            hint: http
              ? 'Terraform n’accepte un miroir réseau qu’en HTTPS : en HTTP, les providers sont copiés dans ~/.terraform.d/plugins, que Terraform consulte automatiquement (relancez après chaque import). Windows : %APPDATA%\\terraform.d\\plugins.'
              : 'Les adresses des providers ne changent pas (hashicorp/aws). En HTTP, utilisez plutôt l’archive : ' + base + '/terraform/providers.tar',
          },
          {
            title: 'Modules',
            code: `module "vpc" {\n  source = "${base}/terraform/modules/terraform-aws-modules/vpc/aws/5.8.1.tar.gz"\n}` + (http ? '' : `\n\n# ou, par le registre du miroir :\nmodule "vpc2" {\n  source  = "${host}/terraform-aws-modules/vpc/aws"\n  version = "5.8.1"\n}`),
            hint: 'Les modules qu’un module appelle doivent aussi être collectés.',
          },
          {
            title: 'Binaire terraform',
            code: `curl -O ${base}/terraform/releases/terraform_1.9.8_linux_amd64.zip\nunzip terraform_1.9.8_linux_amd64.zip && sudo install terraform /usr/local/bin/`,
            hint: 'Disponible si la liste Terraform de Rémus contient « terraform » ; versions et plateformes dans Paquets.',
          },
        ];
      case 'deb': {
        const d = this.currentDistribution();
        if (!d) return [];
        const fp = this.fingerprint().replace(/(.{4})/g, '$1 ').trim();
        return [
          {
            title: 'Clé du miroir (une fois par poste)',
            code: `sudo install -d -m 0755 /etc/apt/keyrings\nsudo wget -qO /etc/apt/keyrings/romulus.gpg ${base}/deb/romulus.gpg\n# ou : sudo curl -fsSLo /etc/apt/keyrings/romulus.gpg ${base}/deb/romulus.gpg`,
            hint: `Romulus signe ses index avec sa propre clé (empreinte ${fp}). Les paquets eux-mêmes sont ceux de Debian, vérifiés par Rémus.`,
          },
          {
            title: 'Dépôt : /etc/apt/sources.list.d/romulus.sources',
            code: `Types: deb\nURIs: ${base}/deb/${d.id}\nSuites: ${d.codename}\nComponents: ${d.components.join(' ') || 'main'}\nSigned-By: /etc/apt/keyrings/romulus.gpg`,
            hint: 'Désactivez les dépôts Internet (/etc/apt/sources.list.d/debian.sources, /etc/apt/sources.list) : sans réseau, apt update échouerait sur eux.',
          },
          {
            title: 'Installer',
            code: `sudo apt update\nsudo apt install nginx`,
            hint: 'Seuls les paquets collectés par Rémus (et leurs dépendances) sont disponibles. Si les paquets recommandés n’ont pas été collectés : apt install --no-install-recommends.',
          },
        ];
      }
      case 'rpm': {
        const d = this.currentDistribution();
        if (!d) return [];
        const tool = d.release === '7' ? 'yum' : 'dnf';
        return [
          {
            title: 'Dépôt : /etc/yum.repos.d/romulus.repo',
            code: d.repoFile ?? '',
            hint: `Ou : sudo curl -o /etc/yum.repos.d/romulus.repo ${base}/rpm/${d.id}/romulus.repo. Les paquets gardent la signature de la distribution (gpgcheck=1, clé déjà présente sur le poste).`,
          },
          {
            title: 'Désactiver les dépôts Internet',
            code: tool === 'yum' ? `sudo yum-config-manager --disable \\* && sudo yum-config-manager --enable romulus-${d.id}` : `sudo dnf config-manager --set-disabled '*'\nsudo dnf config-manager --set-enabled romulus-${d.id}`,
            hint: `Ou déplacez les autres fichiers de /etc/yum.repos.d. Ponctuellement : ${tool} --disablerepo='*' --enablerepo=romulus-${d.id} install …`,
          },
          {
            title: 'Installer',
            code: `sudo ${tool} install nginx`,
            hint: d.release === '8' ? 'EL8 : seuls les streams de modules collectés sont présents (module_hotfixes=1 évite le filtrage modulaire).' : undefined,
          },
        ];
      }
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
