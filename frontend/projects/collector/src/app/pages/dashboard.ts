import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, ECOSYSTEMS, Icon, JobProgress, JobStatus, PluralPipe, RelativePipe, errorMessage, isActive, poll } from '@sas/shared';
import { CollectorApi, Overview } from '../api';
import { inject } from '@angular/core';

@Component({
  imports: [RouterLink, Icon, JobProgress, JobStatus, BytesPipe, RelativePipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>{{ data()?.identity?.name ?? 'Collecteur' }}</h1>
          <p>Télécharge les paquets sur Internet et prépare les bundles à transporter sur clé USB vers le miroir hors ligne.</p>
        </div>
        <div class="actions">
          <a class="btn" routerLink="/listes/nouvelle"><sas-icon name="plus" />Nouvelle liste</a>
          <a class="btn btn-primary" routerLink="/exports"><sas-icon name="usb" />Exporter</a>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        @if (d.lists === 0) {
          <section class="card card-body onboarding">
            <h2>Pour commencer</h2>
            <ol>
              <li><strong>Créez une liste</strong> de paquets (npm, Python, Composer ou Docker), ou importez un fichier de verrouillage.</li>
              <li><strong>Mettez-la à jour</strong> : le collecteur résout les dépendances et télécharge tout.</li>
              <li><strong>Exportez</strong> un bundle vers la clé USB, puis importez-le dans le miroir.</li>
            </ol>
            <a class="btn btn-primary" routerLink="/listes/nouvelle"><sas-icon name="plus" />Créer une première liste</a>
          </section>
        }

        <section class="pending card" [class.has-pending]="pendingTotal() > 0">
          <div class="pending-text">
            <span class="stat-label">À transporter</span>
            @if (pendingTotal() > 0) {
              <strong class="stat-value">{{ pendingTotal() | plural: 'paquet' }}</strong>
              <span class="muted">jamais exportés vers le miroir</span>
            } @else {
              <strong class="stat-value">Tout est exporté</strong>
              <span class="muted">Mettez à jour vos listes pour récupérer les nouvelles versions.</span>
            }
          </div>
          @if (pendingTotal() > 0) {
            <a class="btn btn-primary" routerLink="/exports">Préparer le bundle<sas-icon name="chevron" [size]="16" /></a>
          }
        </section>

        <section class="grid">
          @for (eco of ecosystems; track eco.id) {
            @let stats = d.ecosystems[eco.id];
            <a class="card stat eco" [routerLink]="'/paquets'" [queryParams]="{ ecosystem: eco.id }">
              <span class="stat-label row"><span class="eco-dot" [style.background]="'var(--eco-' + eco.id + ')'"></span>{{ eco.label }}</span>
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
    .pending { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 24px; flex-wrap: wrap; }
    .pending.has-pending { border-color: var(--accent); background: linear-gradient(90deg, var(--accent-soft), var(--surface) 70%); }
    .pending-text { display: grid; gap: 2px; }
    .eco { text-decoration: none; color: inherit; transition: border-color 120ms ease, transform 120ms ease; }
    .eco:hover { border-color: var(--border-strong); transform: translateY(-1px); }
    .columns { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: start; }
    .recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
    .recent li { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
    .recent .title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    hr { border: none; border-top: 1px solid var(--border); margin: 4px 0; width: 100%; }
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
