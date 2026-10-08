import { KeyValuePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, signal, viewChild } from '@angular/core';
import {
  BytesPipe, DateTimePipe, Dialog, EcosystemPipe, Icon, Job, JobProgress, PluralPipe, RelativePipe, Toasts, errorMessage, formatBytes, isActive, poll,
} from '@sas/shared';
import { ImportedBundle, Inbox, InboxItem, MirrorApi } from '../api';

interface Upload {
  name: string;
  size: number;
  sent: number;
  error?: string;
}

@Component({
  imports: [Icon, JobProgress, Dialog, BytesPipe, RelativePipe, DateTimePipe, PluralPipe, EcosystemPipe, KeyValuePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Import</h1>
          <p>Bundles présents sur la clé USB ou envoyés depuis ce navigateur. Chaque fichier est vérifié (signature, empreinte) avant publication.</p>
        </div>
        <button type="button" class="btn" (click)="refresh()"><sas-icon name="refresh" />Actualiser</button>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @for (job of activeJobs(); track job.id) {
        <section class="card card-body"><sas-job-progress [job]="job" /></section>
      }
      @if (lastFailed(); as job) {
        <div class="alert alert-danger"><sas-icon name="alert" /><div><strong>{{ job.title }} : échec</strong><br />{{ job.error }}</div></div>
      }

      <section class="card">
        <header class="card-header">
          <h2>Bundles disponibles</h2>
          @if (inbox(); as box) {
            <div class="sources">
              @for (s of box.sources; track s.id) {
                <span class="badge" [class.badge-ok]="s.available" [title]="s.path">
                  <sas-icon [name]="s.id === 'usb' ? 'usb' : 'upload'" [size]="13" />{{ s.id === 'usb' ? 'Clé USB' : 'Envois' }} : <code>{{ s.path }}</code>
                </span>
              }
            </div>
          }
        </header>

        @if (inbox()?.items?.length === 0) {
          <div class="empty">
            <sas-icon name="usb" [size]="28" />
            <h3>Aucun bundle trouvé</h3>
            <p>Branchez la clé USB (son contenu doit apparaître dans le dossier ci-dessus), ou déposez les fichiers plus bas.</p>
          </div>
        }

        <ul class="bundles">
          @for (item of inbox()?.items ?? []; track item.source + item.name) {
            <li class="bundle">
              <div class="bundle-main">
                <div class="row title">
                  <sas-icon [name]="item.source === 'usb' ? 'usb' : 'upload'" />
                  <strong class="mono">{{ item.name }}</strong>
                  @if (item.report; as r) {
                    <span class="badge badge-accent">n° {{ r.sequence }}</span>
                    <span class="badge">{{ r.full ? 'Complet' : 'Nouveautés' }}</span>
                    @if (r.alreadyImported) { <span class="badge badge-ok"><sas-icon name="check" [size]="12" />Déjà importé</span> }
                  }
                </div>

                @if (item.report; as r) {
                  <p class="muted">
                    {{ r.artifactCount | plural: 'paquet' }} ({{ summary(item) }}) · {{ r.size | bytes }}
                    · de <strong>{{ r.collector.name }}</strong>, {{ r.createdAt | relative }}
                    @if (item.parts.length > 1) { · {{ item.parts.length }} parties }
                  </p>
                  @if (item.missingParts.length) {
                    <div class="alert alert-danger">Partie(s) manquante(s) : {{ partList(item) }}. Copiez tous les fichiers {{ item.name }}.tar.*</div>
                  } @else if (!r.collector.trusted) {
                    <div class="alert alert-warn"><sas-icon name="key" />Collecteur inconnu : approuvez sa clé avant d’importer (empreinte <span class="mono">{{ r.collector.fingerprint }}</span>).</div>
                  } @else if (!r.signatureValid) {
                    <div class="alert alert-danger"><sas-icon name="alert" />Signature invalide : ce bundle a été modifié après sa création. Il ne sera pas importé.</div>
                  } @else if (r.missingArtifactCount > 0) {
                    <div class="alert alert-danger">
                      {{ r.missingArtifactCount | plural: 'paquet' }} dépend(ent) de fichiers d’un bundle précédent non importé
                      @if (r.missingSequences.length) { (n° {{ r.missingSequences.join(', ') }}) }.
                      Importez-le d’abord, ou demandez un export complet.
                    </div>
                  } @else if (r.missingSequences.length) {
                    <div class="alert alert-info">Bundle(s) n° {{ r.missingSequences.join(', ') }} jamais importé(s) ; celui-ci est néanmoins complet.</div>
                  }
                } @else if (item.error) {
                  <div class="alert alert-danger">{{ item.error }}</div>
                }
              </div>

              <div class="bundle-actions">
                @if (item.source === 'upload') {
                  <button type="button" class="btn btn-ghost btn-sm" (click)="removeUpload(item)" title="Supprimer ce fichier envoyé"><sas-icon name="trash" [size]="15" /></button>
                }
                @if (item.report; as r) {
                  @if (!item.missingParts.length) {
                    @if (!r.collector.trusted) {
                      <button type="button" class="btn btn-primary" (click)="askTrust(item)" [disabled]="busy()"><sas-icon name="shield" />Vérifier et importer</button>
                    } @else if (r.importable) {
                      <button type="button" class="btn" [class.btn-primary]="!r.alreadyImported" (click)="start(item)" [disabled]="busy() || activeJobs().length > 0">
                        <sas-icon name="download" />{{ r.alreadyImported ? 'Réimporter' : 'Importer' }}
                      </button>
                    }
                  }
                }
              </div>
            </li>
          }
        </ul>
      </section>

      <section class="card card-body stack">
        <h2>Envoyer depuis cet ordinateur</h2>
        <label class="drop" [class.over]="dragOver()" (dragover)="$event.preventDefault(); dragOver.set(true)" (dragleave)="dragOver.set(false)" (drop)="drop($event)">
          <input type="file" class="visually-hidden" multiple accept=".tar,.001,.002,.003,.004,.005,.006,.007,.008,.009" (change)="pick($event)" />
          <sas-icon name="upload" [size]="22" />
          <span><strong>Déposez les fichiers du bundle</strong> (sas-….tar ou toutes ses parties .001, .002…) ou <u>parcourez</u></span>
          <span class="hint">Envoi par morceaux : un transfert interrompu reprend là où il s’était arrêté.</span>
        </label>
        @for (u of uploads(); track u.name) {
          <div class="upload">
            <div class="row"><sas-icon name="file" [size]="16" /><span class="mono name">{{ u.name }}</span>
              <span class="subtle num">{{ u.sent | bytes }} / {{ u.size | bytes }}</span></div>
            @if (u.error) { <p class="error">{{ u.error }}</p> }
            @else { <div class="progress" role="progressbar" [attr.aria-valuenow]="pct(u)" aria-valuemin="0" aria-valuemax="100" [attr.aria-label]="u.name"><span [style.width.%]="pct(u)"></span></div> }
          </div>
        }
        @if (uploading()) { <div><button type="button" class="btn btn-sm" (click)="cancelUploads()">Annuler l’envoi</button></div> }
      </section>

      <section class="card">
        <header class="card-header"><h2>Historique des imports</h2></header>
        @if (history().length === 0) {
          <div class="empty"><p>Aucun bundle importé pour l’instant.</p></div>
        } @else {
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>N°</th><th>Collecteur</th><th>Contenu</th><th class="right">Nouveaux fichiers</th><th>Créé</th><th>Importé</th></tr></thead>
              <tbody>
                @for (b of history(); track b.id) {
                  <tr>
                    <td><span class="badge badge-accent">{{ b.sequence }}</span></td>
                    <td>{{ b.collectorName }}</td>
                    <td>
                      <strong>{{ b.full ? 'Complet' : 'Nouveautés' }}</strong> · {{ b.artifactCount | plural: 'paquet' }}
                      <div class="subtle">@for (s of b.summary | keyvalue; track s.key; let last = $last) { {{ s.value }} {{ s.key | ecosystem }}{{ last ? '' : ', ' }} }</div>
                    </td>
                    <td class="right num">{{ b.newBlobCount }} · {{ b.size | bytes }}</td>
                    <td class="subtle" [title]="b.createdAt | datetime">{{ b.createdAt | relative }}</td>
                    <td class="subtle" [title]="b.importedAt | datetime">{{ b.importedAt | relative }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </section>
    </div>

    <sas-dialog heading="Approuver ce collecteur ?">
      @if (trusting(); as item) {
        <p>Ce bundle est signé par <strong>{{ item.report?.collector?.name }}</strong>, que ce miroir ne connaît pas encore.</p>
        <p class="muted">Vérifiez que l’empreinte ci-dessous est <strong>identique</strong> à celle affichée dans le collecteur (Réglages › Signature). Sinon, n’importez pas ce bundle.</p>
        <div class="fp"><span class="fingerprint">{{ item.report?.collector?.fingerprint }}</span></div>
        <label class="check"><input type="checkbox" #confirm (change)="confirmed.set(confirm.checked)" /> J’ai comparé l’empreinte : elle est identique.</label>
      }
      <div dialog-actions>
        <button type="button" class="btn" (click)="trustDialog().close()">Annuler</button>
        <button type="button" class="btn btn-primary" [disabled]="!confirmed()" (click)="trustAndImport()"><sas-icon name="shield" />Approuver et importer</button>
      </div>
    </sas-dialog>
  `,
  styles: `
    .sources { display: flex; gap: 6px; flex-wrap: wrap; }
    .sources code { font-size: 0.75rem; }
    .bundles { list-style: none; margin: 0; padding: 0; }
    .bundle { display: flex; gap: 16px; justify-content: space-between; align-items: flex-start; padding: 16px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
    .bundle:last-child { border-bottom: none; }
    .bundle-main { display: grid; gap: 8px; min-width: 0; flex: 1 1 420px; }
    .title { flex-wrap: wrap; }
    .title strong { overflow-wrap: anywhere; }
    .bundle-actions { display: flex; gap: 8px; align-items: center; }
    .drop { display: grid; justify-items: center; gap: 6px; padding: 28px; text-align: center; border: 1.5px dashed var(--border-strong); border-radius: var(--radius);
      color: var(--text-2); cursor: pointer; transition: border-color 120ms ease, background-color 120ms ease; }
    .drop:hover, .drop.over { border-color: var(--accent); background: var(--accent-soft); }
    .drop:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
    .upload { display: grid; gap: 6px; }
    .upload .name { flex: 1; overflow-wrap: anywhere; }
    .error { color: var(--danger); font-size: 0.86rem; }
    .fp { padding: 16px; text-align: center; background: var(--surface-2); border-radius: var(--radius); }
  `,
})
export default class ImportPage {
  private readonly api = inject(MirrorApi);
  private readonly toasts = inject(Toasts);
  protected readonly trustDialog = viewChild.required(Dialog);

  protected readonly inbox = signal<Inbox | null>(null);
  protected readonly history = signal<ImportedBundle[]>([]);
  protected readonly jobs = signal<Job[]>([]);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly dragOver = signal(false);
  protected readonly uploads = signal<Upload[]>([]);
  protected readonly uploading = signal(false);
  protected readonly trusting = signal<InboxItem | null>(null);
  protected readonly confirmed = signal(false);
  protected readonly activeJobs = computed(() => this.jobs().filter(isActive));
  /** Dernier import en échec, s'il est plus récent que le dernier succès */
  protected readonly lastFailed = computed(() => {
    const last = this.jobs().find((j) => !isActive(j));
    const recent = last?.finishedAt && Date.now() - new Date(last.finishedAt).getTime() < 30 * 60_000;
    return last?.status === 'failed' && recent ? last : null;
  });
  private abort?: AbortController;
  private wasActive = false;

  constructor() {
    poll(async () => {
      await this.loadJobs();
      return this.activeJobs().length > 0;
    });
    void this.refresh();
  }

  protected summary(item: InboxItem): string {
    return Object.entries(item.report?.summary ?? {})
      .map(([eco, n]) => `${n} ${eco === 'pypi' ? 'Python' : eco === 'composer' ? 'Composer' : eco === 'docker' ? 'Docker' : 'npm'}`)
      .join(', ');
  }

  protected partList(item: InboxItem): string {
    return item.missingParts.map((n) => String(n).padStart(3, '0')).join(', ');
  }

  protected pct(u: Upload): number {
    return u.size ? Math.round((u.sent * 100) / u.size) : 0;
  }

  async refresh(): Promise<void> {
    try {
      const [inbox, history] = await Promise.all([this.api.inbox(), this.api.imports()]);
      this.inbox.set(inbox);
      this.history.set(history);
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private async loadJobs(): Promise<void> {
    const jobs = await this.api.jobs();
    this.jobs.set(jobs.filter((j) => j.type === 'import'));
    const active = this.activeJobs().length > 0;
    if (this.wasActive && !active) {
      const last = this.jobs()[0];
      if (last?.status === 'succeeded') this.toasts.ok(last.step);
      await this.refresh();
    }
    this.wasActive = active;
  }

  protected askTrust(item: InboxItem): void {
    this.trusting.set(item);
    this.confirmed.set(false);
    this.trustDialog().open();
  }

  protected async trustAndImport(): Promise<void> {
    const item = this.trusting();
    const fingerprint = item?.report?.collector.fingerprint;
    if (!item || !fingerprint) return;
    this.trustDialog().close();
    await this.launch(item, { fingerprint });
  }

  protected start(item: InboxItem): Promise<void> {
    return this.launch(item);
  }

  private async launch(item: InboxItem, trust?: { fingerprint: string }): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.importBundle(item.source, item.name, trust);
      this.wasActive = true;
      await this.loadJobs();
      await this.refresh();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected async removeUpload(item: InboxItem): Promise<void> {
    try {
      await this.api.deleteUpload(item.name);
      await this.refresh();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected pick(event: Event): void {
    const input = event.target as HTMLInputElement;
    void this.send([...(input.files ?? [])]);
    input.value = '';
  }

  protected drop(event: DragEvent): void {
    event.preventDefault();
    this.dragOver.set(false);
    void this.send([...(event.dataTransfer?.files ?? [])]);
  }

  protected cancelUploads(): void {
    this.abort?.abort();
  }

  private async send(files: File[]): Promise<void> {
    if (!files.length || this.uploading()) return;
    files.sort((a, b) => a.name.localeCompare(b.name));
    this.uploads.set(files.map((f) => ({ name: f.name, size: f.size, sent: 0 })));
    this.uploading.set(true);
    this.abort = new AbortController();
    let failed = false;
    for (const [i, file] of files.entries()) {
      try {
        await this.api.upload(file, (sent) => this.patch(i, { sent }), this.abort.signal);
      } catch (e) {
        failed = true;
        this.patch(i, { error: e instanceof DOMException ? 'Envoi annulé (il reprendra au prochain dépôt du même fichier).' : errorMessage(e) });
        if (e instanceof DOMException) break;
      }
    }
    this.uploading.set(false);
    if (!failed) {
      this.toasts.ok(`${files.length} fichier(s) envoyé(s), ${formatBytes(files.reduce((s, f) => s + f.size, 0))}.`);
      this.uploads.set([]);
    }
    await this.refresh();
  }

  private patch(index: number, change: Partial<Upload>): void {
    this.uploads.update((list) => list.map((u, i) => (i === index ? { ...u, ...change } : u)));
  }
}
