import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, ECOSYSTEMS, Eco, Icon, Logo, JobProgress, JobStatus, PluralPipe, RelativePipe, errorMessage, isActive, poll } from '@sas/shared';
import { CollectorApi, Overview } from '../api';
import { inject } from '@angular/core';

@Component({
  imports: [RouterLink, Icon, Logo, Eco, JobProgress, JobStatus, BytesPipe, RelativePipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        @if (d.lists === 0) {
          <section class="card card-body onboarding">
            <h2>Pour commencer</h2>
            <ol>
              <li><strong>Créez une liste</strong> de paquets (npm, Python, Composer ou Docker), ou importez un fichier de verrouillage.</li>
              <li><strong>Mettez-la à jour</strong> : le collecteur résout les dépendances et télécharge tout.</li>
              <li><strong>Exportez</strong> un bundle vers la clé USB, puis importez-le dans Romulus.</li>
            </ol>
            <a class="btn btn-primary" routerLink="/listes/nouvelle"><sas-icon name="plus" />Créer une première liste</a>
          </section>
        }

        <section class="hero">
          <sas-logo class="watermark" name="remus" [size]="280" [bare]="true" />
          <div>
            <span class="eyebrow">Rémus · collecteur</span>
            <h1>{{ d.identity.name }}</h1>
            <p>Télécharge les paquets sur Internet et prépare les bundles à transporter sur clé USB vers Romulus, le miroir hors ligne.</p>
          </div>
          <div class="hero-stats">
            <div><strong>{{ totalArtifacts().toLocaleString('fr-FR') }}</strong><span>paquets collectés</span></div>
            <div><strong>{{ pendingTotal().toLocaleString('fr-FR') }}</strong><span>à transporter</span></div>
            <div><strong>{{ d.storage.size | bytes }}</strong><span>stockés</span></div>
            <div><strong>{{ d.lastExport ? 'n° ' + d.lastExport.sequence : '—' }}</strong><span>dernier bundle</span></div>
          </div>
          <div class="actions">
            <a class="btn btn-primary" routerLink="/exports"><sas-icon name="usb" />{{ pendingTotal() > 0 ? 'Préparer le bundle' : 'Exports' }}</a>
            <a class="btn" routerLink="/listes/nouvelle"><sas-icon name="plus" />Nouvelle liste</a>
          </div>
        </section>

        <section class="grid">
          @for (eco of ecosystems; track eco.id) {
            @let stats = d.ecosystems[eco.id];
            <a class="card stat" [routerLink]="'/paquets'" [queryParams]="{ ecosystem: eco.id }">
              <span class="stat-label row"><sas-eco [ecosystem]="eco.id" />{{ eco.label }}</span>
              <span class="stat-value">{{ (stats?.artifacts ?? 0).toLocaleString('fr-FR') }}</span>
              <span class="subtle">{{ stats?.size ?? 0 | bytes }}@if (stats?.pending) { · <span class="badge badge-accent">{{ stats?.pending }} à exporter</span> }</span>
            </a>
          }
        </section>

        <div class="columns">
          <section class="card">
            <header class="card-header"><h2>Activité</h2><a routerLink="/activite" class="subtle">Tout voir</a></header>
            <div class="card-body stack">
              @for (job of d.activeJobs; track job.id) { <sas-job-progress [job]="job" /> }
              @if (d.recentJobs.length === 0) { <p class="muted">Aucune tâche pour l’instant.</p> }
              <ul class="recent">
                @for (job of finishedJobs(); track job.id) {
                  <li>
                    <sas-job-status [status]="job.status" />
                    <span class="title">{{ job.title }}</span>
                    <span class="subtle">{{ job.finishedAt | relative }}</span>
                  </li>
                }
              </ul>
            </div>
          </section>

          <section class="card">
            <header class="card-header"><h2>Dernier export</h2><a routerLink="/exports" class="subtle">Exports</a></header>
            <div class="card-body stack">
              @if (d.lastExport; as e) {
                <div class="row"><span class="badge badge-accent">n° {{ e.sequence }}</span><span class="badge">{{ e.full ? 'Complet' : 'Nouveautés' }}</span></div>
                <p><strong>{{ e.artifactCount | plural: 'paquet' }}</strong>, {{ e.size | bytes }}</p>
                <p class="subtle">{{ e.createdAt | relative }} · <code>{{ e.baseName }}</code></p>
              } @else {
                <p class="muted">Aucun export pour l’instant.</p>
              }
              <hr />
              <div class="kv">
                <span class="subtle">Stockage</span><span class="num">{{ d.storage.size | bytes }} · {{ d.storage.blobs | plural: 'fichier' }}</span>
                <span class="subtle">Espace libre</span><span class="num">{{ d.storage.free | bytes }}</span>
                <span class="subtle">Empreinte</span><span class="mono">{{ d.identity.fingerprint }}</span>
              </div>
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
    .columns { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: start; }
    .recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .recent li { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
    .recent .title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    hr { border: none; border-top: 1px solid var(--border); margin: 4px 0; width: 100%; }
    .recent li { padding: 2px 0; }
    .kv { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: 0.9rem; }
    .card-header a { text-decoration: none; }
  `,
})
export default class Dashboard {
  private readonly api = inject(CollectorApi);
  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly data = signal<Overview | null>(null);
  protected readonly error = signal('');
  protected readonly pendingTotal = computed(() =>
    Object.values(this.data()?.ecosystems ?? {}).reduce((sum, e) => sum + (e?.pending ?? 0), 0),
  );
  protected readonly totalArtifacts = computed(() =>
    Object.values(this.data()?.ecosystems ?? {}).reduce((sum, e) => sum + (e?.artifacts ?? 0), 0),
  );
  protected readonly finishedJobs = computed(() => (this.data()?.recentJobs ?? []).filter((j) => !isActive(j)).slice(0, 6));

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
