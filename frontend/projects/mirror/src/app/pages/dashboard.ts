import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, ECOSYSTEMS, Icon, JobProgress, PluralPipe, RelativePipe, errorMessage, poll } from '@sas/shared';
import { MirrorApi, Overview } from '../api';

@Component({
  imports: [RouterLink, Icon, JobProgress, BytesPipe, RelativePipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Miroir hors ligne</h1>
          <p>Sert les dépôts npm, Python, Composer et Docker aux postes du réseau isolé, à partir des bundles apportés par clé USB depuis Rémus.</p>
        </div>
        <div class="actions">
          <a class="btn" routerLink="/utilisation"><sas-icon name="terminal" />Configurer les postes</a>
          <a class="btn btn-primary" routerLink="/import"><sas-icon name="usb" />Importer un bundle</a>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        @for (job of d.activeJobs; track job.id) {
          <section class="card card-body"><sas-job-progress [job]="job" /></section>
        }

        @if (empty()) {
          <section class="card card-body onboarding">
            <h2>Pour commencer</h2>
            <ol>
              <li>Dans Rémus, le collecteur connecté à Internet, créez des listes et <strong>exportez un bundle</strong> sur une clé USB.</li>
              <li>Branchez la clé ici et ouvrez <strong>Import</strong> ; approuvez la clé du collecteur après avoir comparé son empreinte.</li>
              <li>Configurez npm, pip, Composer et Docker sur les postes : voir <strong>Utilisation</strong>.</li>
            </ol>
            <a class="btn btn-primary" routerLink="/import"><sas-icon name="usb" />Importer un premier bundle</a>
          </section>
        }

        <section class="grid">
          @for (eco of ecosystems; track eco.id) {
            @let stats = d.ecosystems[eco.id];
            <a class="card stat eco" routerLink="/paquets" [queryParams]="{ ecosystem: eco.id }">
              <span class="stat-label row"><span class="eco-dot" [style.background]="'var(--eco-' + eco.id + ')'"></span>{{ eco.label }}</span>
              <span class="stat-value">{{ (stats?.packages ?? 0).toLocaleString('fr-FR') }}</span>
              <span class="subtle">{{ stats?.files ?? 0 | plural: (eco.id === 'docker' ? 'étiquette' : eco.id === 'pypi' ? 'fichier' : 'version') }} · {{ stats?.size ?? 0 | bytes }}</span>
            </a>
          }
        </section>

        <div class="columns">
          <section class="card">
            <header class="card-header"><h2>Dernier import</h2><a routerLink="/import" class="subtle">Import</a></header>
            <div class="card-body stack">
              @if (d.lastImport; as b) {
                <div class="row"><span class="badge badge-accent">n° {{ b.sequence }}</span><span class="badge">{{ b.full ? 'Complet' : 'Nouveautés' }}</span></div>
                <p><strong>{{ b.artifactCount | plural: 'paquet' }}</strong> de {{ b.collectorName }}</p>
                <p class="subtle">Importé {{ b.importedAt | relative }} · créé {{ b.createdAt | relative }}</p>
              } @else {
                <p class="muted">Aucun import pour l’instant.</p>
              }
            </div>
          </section>

          <section class="card">
            <header class="card-header"><h2>Serveur</h2><a routerLink="/reglages" class="subtle">Réglages</a></header>
            <div class="card-body kv">
              <span class="subtle">Adresse</span><code>{{ d.baseUrl }}</code>
              <span class="subtle">Stockage</span><span class="num">{{ d.storage.size | bytes }}</span>
              <span class="subtle">Espace libre</span><span class="num">{{ d.storage.free | bytes }}</span>
              <span class="subtle">Collecteurs approuvés</span><span class="num">{{ d.trustedKeys }}</span>
            </div>
          </section>
        </div>
      }
    </div>
  `,
  styles: `
    .onboarding { display: grid; gap: 12px; justify-items: start; }
    .onboarding ol { margin: 0; padding-left: 20px; display: grid; gap: 6px; color: var(--text-2); }
    .eco { text-decoration: none; color: inherit; transition: border-color 120ms ease, transform 120ms ease; }
    .eco:hover { border-color: var(--border-strong); transform: translateY(-1px); }
    .columns { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: start; }
    .kv { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 0.9rem; }
    .kv code { overflow-wrap: anywhere; }
    .card-header a { text-decoration: none; }
  `,
})
export default class Dashboard {
  private readonly api = inject(MirrorApi);
  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly data = signal<Overview | null>(null);
  protected readonly error = signal('');
  protected readonly empty = computed(() => Object.keys(this.data()?.ecosystems ?? {}).length === 0);

  constructor() {
    poll(async () => {
      try {
        this.data.set(await this.api.overview());
        this.error.set('');
      } catch (e) {
        this.error.set(errorMessage(e));
      }
      return (this.data()?.activeJobs.length ?? 0) > 0;
    });
  }
}
