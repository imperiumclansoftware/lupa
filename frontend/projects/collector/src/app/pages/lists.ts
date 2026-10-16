import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, EcosystemPipe, Icon, JobStatus, SecuritySummary, RelativePipe, Toasts, errorMessage, isActive, poll, Eco } from '@sas/shared';
import { CollectorApi, PackageList } from '../api';

@Component({
  imports: [Eco, SecuritySummary, RouterLink, Icon, JobStatus, BytesPipe, RelativePipe, EcosystemPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Listes</h1>
          <p>Chaque liste décrit des paquets à collecter pour un écosystème. Leur mise à jour résout les dépendances et télécharge ce qui manque.</p>
        </div>
        <div class="actions">
          @if (lists().length > 0) {
            <button type="button" class="btn" (click)="resolveAll()" [disabled]="busy()"><sas-icon name="refresh" />Tout mettre à jour</button>
          }
          <a class="btn btn-primary" routerLink="/listes/nouvelle"><sas-icon name="plus" />Nouvelle liste</a>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      <section class="card">
        @if (loaded() && lists().length === 0) {
          <div class="empty">
            <sas-icon name="list" [size]="28" />
            <h3>Aucune liste</h3>
            <p>Créez une liste de paquets npm, Python, Composer, Debian, RPM, Ansible, Terraform ou d’images Docker.</p>
            <a class="btn btn-primary" routerLink="/listes/nouvelle"><sas-icon name="plus" />Nouvelle liste</a>
          </div>
        } @else {
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr><th>Liste</th><th>Écosystème</th><th class="right">Paquets</th><th class="right">Taille</th><th>À exporter</th><th>Sécurité</th><th>Mise à jour</th></tr>
              </thead>
              <tbody>
                @for (list of lists(); track list.id) {
                  <tr>
                    <td>
                      <a class="row-link" [routerLink]="['/listes', list.id]">{{ list.name }}</a>
                      <div class="subtle">{{ list.specCount }} entrée(s)@if (list.lockfileName) { · <span class="mono">{{ list.lockfileName }}</span> }</div>
                    </td>
                    <td><span class="row"><sas-eco [ecosystem]="list.ecosystem" [size]="26" />{{ list.ecosystem | ecosystem }}</span></td>
                    <td class="right num">{{ list.artifactCount }}</td>
                    <td class="right num">{{ list.size | bytes }}</td>
                    <td>@if (list.pendingCount > 0) { <span class="badge badge-accent">{{ list.pendingCount }}</span> } @else { <span class="subtle">—</span> }</td>
                    <td>@if (list.ecosystem === 'docker') { <span class="subtle" title="Les images Docker ne sont pas analysées">—</span> } @else { <sas-security-summary [counts]="list.security" [showOk]="!!list.resolvedAt" /> }</td>
                    <td>
                      @if (list.job) { <sas-job-status [status]="list.job.status" /> }
                      @else if (list.resolvedAt) { <span class="subtle">{{ list.resolvedAt | relative }}</span> }
                      @else { <span class="badge badge-warn">Jamais</span> }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    </div>
  `,
})
export default class Lists {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly lists = signal<PackageList[]>([]);
  protected readonly loaded = signal(false);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly active = computed(() => this.lists().some((l) => isActive(l.job)));

  constructor() {
    poll(async () => {
      await this.load();
      return this.active();
    });
  }

  private async load(): Promise<void> {
    try {
      this.lists.set(await this.api.lists());
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.loaded.set(true);
    }
  }

  protected async resolveAll(): Promise<void> {
    this.busy.set(true);
    let launched = 0;
    for (const list of this.lists()) {
      try {
        await this.api.resolve(list.id);
        launched++;
      } catch (e) {
        this.toasts.error(`${list.name} : ${errorMessage(e)}`);
      }
    }
    this.busy.set(false);
    if (launched) this.toasts.ok(`${launched} mise(s) à jour lancée(s).`);
    await this.load();
  }
}
