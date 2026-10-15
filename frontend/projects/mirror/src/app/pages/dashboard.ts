import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, ECOSYSTEMS, Eco, SecuritySummary, Icon, Logo, JobProgress, PluralPipe, RelativePipe, errorMessage, poll } from '@sas/shared';
import { MirrorApi, Overview } from '../api';

@Component({
  imports: [RouterLink, Icon, Logo, Eco, SecuritySummary, JobProgress, BytesPipe, RelativePipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        <section class="hero">
          <sas-logo class="watermark" name="romulus" [size]="280" [bare]="true" />
          <div>
            <span class="eyebrow">Romulus · miroir hors ligne</span>
            <h1>Dépôts du réseau isolé</h1>
            <p>Sert npm, Python, Composer et Docker aux postes du réseau, à partir des bundles signés apportés par clé USB depuis Rémus.</p>
          </div>
          <div class="hero-stats">
            <div><strong>{{ totalPackages().toLocaleString('fr-FR') }}</strong><span>paquets servis</span></div>
            <div><strong>{{ d.storage.size | bytes }}</strong><span>stockés</span></div>
            <div><strong>{{ d.lastImport ? 'n° ' + d.lastImport.sequence : '—' }}</strong><span>dernier bundle</span></div>
            <div><strong>{{ d.trustedKeys }}</strong><span>collecteur(s) approuvé(s)</span></div>
          </div>
          <div class="actions">
            <a class="btn btn-primary" routerLink="/import"><sas-icon name="usb" />Importer un bundle</a>
            <a class="btn" routerLink="/utilisation"><sas-icon name="terminal" />Configurer les postes</a>
          </div>
        </section>

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
            <a class="card stat" routerLink="/paquets" [queryParams]="{ ecosystem: eco.id }">
              <span class="stat-label row"><sas-eco [ecosystem]="eco.id" />{{ eco.label }}</span>
              <span class="stat-value">{{ (stats?.packages ?? 0).toLocaleString('fr-FR') }}</span>
              <span class="subtle">{{ stats?.files ?? 0 | plural: (eco.id === 'docker' ? 'étiquette' : eco.id === 'pypi' ? 'fichier' : 'version') }} · {{ stats?.size ?? 0 | bytes }}</span>
            </a>
          }
        </section>

        @if (d.security.critical || d.security.warning) {
          <a class="card security" routerLink="/paquets" [class.critical]="d.security.critical">
            <sas-icon name="alert" [size]="22" />
            <span class="grow"><strong>Alertes de sécurité</strong>
              <span class="muted">Rémus a signalé des vulnérabilités connues sur des versions servies : informez les équipes et importez des versions corrigées.</span></span>
            <sas-security-summary [counts]="d.security" />
          </a>
        }

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
    a.stat { text-decoration: none; color: inherit; }
    .stat-label { gap: 10px; margin-bottom: 6px; }
    .security { display: flex; align-items: center; gap: 14px; padding: 16px 22px; color: inherit; text-decoration: none; flex-wrap: wrap; }
    .security > sas-icon:first-child { color: var(--warn); }
    .security.critical > sas-icon:first-child { color: var(--danger); }
    .security .grow { flex: 1; display: grid; gap: 2px; min-width: 220px; }
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
  protected readonly totalPackages = computed(() =>
    Object.values(this.data()?.ecosystems ?? {}).reduce((sum, e) => sum + (e?.packages ?? 0), 0),
  );
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
