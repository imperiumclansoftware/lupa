import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal, viewChild } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { Dialog } from './dialog';
import { DateTimePipe, RelativePipe } from './format';
import { Icon } from './icon';

export interface Job {
  id: number;
  type: string;
  title: string;
  status: 'queued' | 'running' | 'succeeded' | 'failed';
  progress: number | null;
  step: string;
  error: string | null;
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  log?: string;
}

export function isActive(job: Job | null | undefined): boolean {
  return !!job && (job.status === 'queued' || job.status === 'running');
}

/**
 * Rafraîchit périodiquement tant que la page est ouverte : toutes les 2 s si une tâche tourne, 15 s sinon.
 * Arrêté automatiquement à la destruction du composant.
 */
export function poll(refresh: () => Promise<boolean>, destroyRef = inject(DestroyRef)): void {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let stopped = false;
  const tick = async () => {
    let busy = false;
    try {
      busy = await refresh();
    } catch {
      // Erreur réseau passagère : on réessaie au prochain tour
    }
    if (!stopped) timer = setTimeout(tick, busy ? 2000 : 15000);
  };
  void tick();
  destroyRef.onDestroy(() => {
    stopped = true;
    clearTimeout(timer);
  });
}

@Component({
  selector: 'sas-job-status',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="badge" [class]="'badge ' + css()">{{ label() }}</span>`,
})
export class JobStatus {
  readonly status = input.required<Job['status']>();
  protected readonly label = computed(() => ({ queued: 'En attente', running: 'En cours', succeeded: 'Terminée', failed: 'Échec' })[this.status()]);
  protected readonly css = computed(() => ({ queued: '', running: 'badge-accent', succeeded: 'badge-ok', failed: 'badge-danger' })[this.status()]);
}

/** Progression d'une tâche active (barre + étape en cours), ou son résultat. */
@Component({
  selector: 'sas-job-progress',
  imports: [JobStatus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let j = job();
    <div class="job">
      <div class="row head">
        <strong>{{ j.title }}</strong>
        <sas-job-status [status]="j.status" />
      </div>
      @if (j.status === 'queued' || j.status === 'running') {
        <div class="progress" [class.indeterminate]="j.progress === null" role="progressbar"
          [attr.aria-valuenow]="j.progress" aria-valuemin="0" aria-valuemax="100" [attr.aria-label]="j.title">
          <span [style.width.%]="j.progress ?? 0"></span>
        </div>
      }
      <p class="subtle step">{{ j.status === 'failed' ? j.error : j.step }}</p>
    </div>
  `,
  styles: `
    .job { display: grid; gap: 8px; }
    .head { justify-content: space-between; }
    .step { white-space: pre-line; overflow-wrap: anywhere; }
  `,
})
export class JobProgress {
  readonly job = input.required<Job>();
}

/** Historique des tâches avec journal détaillé. */
@Component({
  selector: 'sas-jobs-page',
  imports: [Icon, JobStatus, Dialog, RelativePipe, DateTimePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Activité</h1>
          <p>{{ intro() }}</p>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      <section class="card">
        @if (jobs().length === 0) {
          <div class="empty"><sas-icon name="activity" [size]="28" /><h3>Aucune tâche pour l’instant</h3></div>
        } @else {
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Tâche</th><th>État</th><th>Détail</th><th>Lancée</th><th></th></tr></thead>
              <tbody>
                @for (job of jobs(); track job.id) {
                  <tr>
                    <td><strong>{{ job.title }}</strong></td>
                    <td><sas-job-status [status]="job.status" /></td>
                    <td class="detail">
                      @if (job.status === 'running' && job.progress !== null) { <span class="num">{{ job.progress }} %</span> · }
                      {{ job.status === 'failed' ? job.error : job.step }}
                    </td>
                    <td class="subtle" [title]="job.createdAt | datetime">{{ job.createdAt | relative }}</td>
                    <td class="right"><button type="button" class="btn btn-sm" (click)="open(job)">Journal</button></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    </div>

    <sas-dialog [heading]="selected()?.title ?? 'Journal'" (closed)="selected.set(null)">
      @if (selected(); as job) {
        <div class="row"><sas-job-status [status]="job.status" /><span class="subtle">{{ job.startedAt | datetime }}</span></div>
        @if (job.error) { <div class="alert alert-danger">{{ job.error }}</div> }
        <pre class="log">{{ job.log || 'Journal vide.' }}</pre>
      }
    </sas-dialog>
  `,
  styles: `
    .detail { max-width: 460px; color: var(--text-2); font-size: 0.86rem; overflow-wrap: anywhere; white-space: pre-line; }
    :host ::ng-deep dialog.sas-dialog { width: min(820px, calc(100vw - 32px)); }
  `,
})
export class JobsPage implements OnInit {
  private readonly http = inject(HttpClient);
  readonly intro = input('Tâches de fond récentes et leur journal.');
  protected readonly jobs = signal<Job[]>([]);
  protected readonly selected = signal<Job | null>(null);
  protected readonly error = signal('');
  private readonly destroyRef = inject(DestroyRef);
  private readonly dialog = viewChild.required(Dialog);

  ngOnInit(): void {
    poll(async () => {
      try {
        this.jobs.set(await firstValueFrom(this.http.get<Job[]>('/api/jobs')));
        this.error.set('');
        const current = this.selected();
        if (current) this.selected.set(await firstValueFrom(this.http.get<Job>(`/api/jobs/${current.id}`)));
      } catch (e) {
        this.error.set(errorMessage(e));
      }
      return this.jobs().some(isActive);
    }, this.destroyRef);
  }

  protected async open(job: Job): Promise<void> {
    this.selected.set(await firstValueFrom(this.http.get<Job>(`/api/jobs/${job.id}`)));
    this.dialog().open();
  }
}
