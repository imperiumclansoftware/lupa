import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { BytesPipe, DateTimePipe, EcosystemPipe, Icon, JobProgress, PluralPipe, RelativePipe, Toasts, errorMessage, isActive, poll } from '@sas/shared';
import { CollectorApi, Export, Exports } from '../api';
import { KeyValuePipe } from '@angular/common';

@Component({
  imports: [FormsModule, Icon, JobProgress, BytesPipe, RelativePipe, DateTimePipe, PluralPipe, EcosystemPipe, KeyValuePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Exports</h1>
          <p>Un export écrit un bundle signé dans le dossier d’export (la clé USB). Copiez-le sur le miroir et importez-le.</p>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        @if (activeJob(); as job) {
          <section class="card card-body"><sas-job-progress [job]="job" /></section>
        }

        <section class="card">
          <header class="card-header"><h2>Nouveau bundle</h2></header>
          <div class="card-body stack">
            <div class="modes" role="radiogroup" aria-label="Contenu du bundle">
              <label class="mode" [class.selected]="!full()">
                <input type="radio" name="mode" [checked]="!full()" (change)="full.set(false)" />
                <span class="mode-title">Nouveautés</span>
                <span class="muted">Uniquement ce qui n’a jamais été exporté. À utiliser à chaque passage.</span>
                <span class="mode-stats num">{{ d.pending.artifacts | plural: 'paquet' }} · {{ d.pending.size | bytes }}</span>
              </label>
              <label class="mode" [class.selected]="full()">
                <input type="radio" name="mode" [checked]="full()" (change)="full.set(true)" />
                <span class="mode-title">Complet</span>
                <span class="muted">Tout le contenu du collecteur. Pour un nouveau miroir ou après une clé perdue.</span>
                <span class="mode-stats num">{{ d.full.artifacts | plural: 'paquet' }} · {{ d.full.size | bytes }}</span>
              </label>
            </div>

            <div class="field split">
              <label for="split">Découpage</label>
              <select class="select" id="split" [ngModel]="split()" (ngModelChange)="split.set($event)">
                <option [ngValue]="null">Un seul fichier (clé exFAT, NTFS ou ext4)</option>
                <option [ngValue]="4095">Parties de 4 Go (clé FAT32)</option>
                <option [ngValue]="2048">Parties de 2 Go</option>
                <option [ngValue]="1024">Parties de 1 Go</option>
              </select>
              <span class="hint">Une clé FAT32 refuse les fichiers de plus de 4 Go. Les parties sont réassemblées à l’import.</span>
            </div>

            @if (tooBig()) {
              <div class="alert alert-warn"><sas-icon name="alert" />Espace libre insuffisant dans le dossier d’export ({{ d.freeSpace | bytes }}).</div>
            }

            <div class="row footer">
              <span class="subtle">Destination : <code>{{ d.exportDir }}</code> · {{ d.freeSpace | bytes }} libres</span>
              <button type="button" class="btn btn-primary" (click)="create()" [disabled]="busy() || !!activeJob() || selectedCount() === 0">
                <sas-icon name="usb" />Créer le bundle
              </button>
            </div>
          </div>
        </section>

        <section class="card">
          <header class="card-header"><h2>Historique</h2></header>
          @if (d.items.length === 0) {
            <div class="empty"><sas-icon name="usb" [size]="28" /><h3>Aucun export</h3><p>Le premier export contiendra tout ce qui a été téléchargé.</p></div>
          } @else {
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>N°</th><th>Contenu</th><th class="right">Taille</th><th>Fichiers</th><th>Créé</th><th></th></tr></thead>
                <tbody>
                  @for (e of d.items; track e.id) {
                    <tr>
                      <td><span class="badge badge-accent">{{ e.sequence }}</span></td>
                      <td>
                        <strong>{{ e.full ? 'Complet' : 'Nouveautés' }}</strong> · {{ e.artifactCount | plural: 'paquet' }}
                        <div class="subtle">@for (s of e.summary | keyvalue; track s.key; let last = $last) { {{ s.value }} {{ s.key | ecosystem }}{{ last ? '' : ', ' }} }</div>
                      </td>
                      <td class="right num">{{ e.size | bytes }}</td>
                      <td>
                        <ul class="files">
                          @for (f of e.files; track f.name) {
                            <li>
                              @if (f.present) {
                                <a [href]="'/api/exports/' + e.id + '/files/' + f.name" download class="mono">{{ f.name }}</a>
                              } @else {
                                <span class="mono subtle" title="Fichier retiré du dossier d’export">{{ f.name }}</span>
                              }
                            </li>
                          }
                        </ul>
                      </td>
                      <td class="subtle" [title]="e.createdAt | datetime">{{ e.createdAt | relative }}</td>
                      <td class="right">
                        @if (hasFiles(e)) {
                          <button type="button" class="btn btn-sm btn-ghost" (click)="clean(e)" title="Supprimer les fichiers du dossier d’export">
                            <sas-icon name="trash" [size]="15" />Retirer
                          </button>
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </section>

        <section class="card card-body steps">
          <h2>Transporter un bundle</h2>
          <ol>
            <li>Copiez tous les fichiers du bundle (<code>sas-….tar</code> ou <code>sas-….tar.001</code>, <code>.002</code>…) sur la clé USB, ou téléchargez-les ci-dessus.</li>
            <li>Branchez la clé sur le serveur du miroir, ouvrez <strong>Import</strong> dans le miroir et choisissez le bundle.</li>
            <li>À la première fois, comparez l’empreinte affichée par le miroir avec celle des <strong>Réglages</strong> du collecteur.</li>
          </ol>
          <p class="subtle">Importez les bundles dans l’ordre des numéros : un bundle « Nouveautés » s’appuie sur les précédents.</p>
        </section>
      }
    </div>
  `,
  styles: `
    .modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
    .mode { position: relative; display: grid; gap: 4px; padding: 16px 16px 16px 44px; border: 1px solid var(--border-strong); border-radius: var(--radius); cursor: pointer;
      transition: border-color 120ms ease, background-color 120ms ease; }
    .mode input { position: absolute; left: 16px; top: 19px; margin: 0; }
    .mode.selected { border-color: var(--accent); background: var(--accent-soft); }
    .mode-title { font-weight: 700; }
    .mode-stats { font-weight: 600; margin-top: 4px; }
    .split { max-width: 420px; }
    .footer { justify-content: space-between; flex-wrap: wrap; }
    .files { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; font-size: 0.82rem; }
    .files a { overflow-wrap: anywhere; }
    .steps { display: grid; gap: 10px; }
    .steps ol { margin: 0; padding-left: 20px; display: grid; gap: 6px; color: var(--text-2); }
  `,
})
export default class ExportsPage {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly data = signal<Exports | null>(null);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly full = signal(false);
  protected readonly split = signal<number | null>(null);
  protected readonly activeJob = computed(() => this.data()?.items.find((e) => isActive(e.job))?.job ?? null);
  protected readonly selectedCount = computed(() => (this.full() ? this.data()?.full.artifacts : this.data()?.pending.artifacts) ?? 0);
  protected readonly tooBig = computed(() => {
    const d = this.data();
    const size = this.full() ? d?.full.size : d?.pending.size;
    return d?.freeSpace != null && size != null && size > d.freeSpace;
  });
  private wasActive = false;

  constructor() {
    poll(async () => {
      await this.load();
      return !!this.activeJob() || this.busy();
    });
  }

  protected hasFiles(e: Export): boolean {
    return e.files.some((f) => f.present);
  }

  private async load(): Promise<void> {
    try {
      this.data.set(await this.api.exports());
      this.error.set('');
      const active = !!this.activeJob();
      if (this.wasActive && !active) {
        const last = this.data()?.items[0];
        if (last?.job?.status === 'succeeded') this.toasts.ok(`Bundle n° ${last.sequence} prêt : ${last.files.length} fichier(s).`);
      }
      this.wasActive = active;
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async create(): Promise<void> {
    this.busy.set(true);
    try {
      const job = await this.api.createExport(this.full(), this.split());
      this.wasActive = true;
      await this.load();
      if (job.status === 'failed') this.error.set(job.error ?? 'Échec de l’export.');
    } catch (e) {
      this.error.set(errorMessage(e));
      await this.load();
    } finally {
      this.busy.set(false);
    }
  }

  protected async clean(e: Export): Promise<void> {
    try {
      await this.api.deleteExportFiles(e.id);
      this.toasts.ok(`Fichiers du bundle n° ${e.sequence} retirés du dossier d’export.`);
      await this.load();
    } catch (err) {
      this.error.set(errorMessage(err));
    }
  }
}
