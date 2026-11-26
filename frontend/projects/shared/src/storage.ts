import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { Dialog } from './dialog';
import { DateTimePipe } from './format';
import { Icon } from './icon';
import { Job, JobProgress, isActive, poll } from './jobs';
import { Toasts } from './toast';

export interface S3Config {
  endpoint: string;
  region: string;
  bucket: string;
  prefix: string;
  pathStyle: boolean;
  accessKey: string;
}

export interface StorageState {
  active: 'local' | 's3';
  location: string;
  local: string;
  s3: (S3Config & { hasSecret: boolean }) | null;
  job: Job | null;
  last: Job | null;
}

/** Réglages › Stockage (administrateurs) : disque ou bucket S3, migration de l'un à l'autre. */
@Component({
  selector: 'sas-storage',
  imports: [FormsModule, Icon, Dialog, DateTimePipe, JobProgress],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    }
    @if (state(); as s) {
      <section class="card card-body stack">
        <div class="row">
          <sas-icon name="hdd" />
          <h2>Stockage des paquets</h2>
        </div>
        <div class="kv">
          <span class="subtle">Moteur actif</span>
          <span>
            <span class="badge" [class.badge-accent]="s.active === 's3'">{{
              s.active === 's3' ? 'Bucket S3' : 'Disque'
            }}</span>
          </span>
          <span class="subtle">Emplacement</span><code>{{ s.location }}</code>
        </div>
        @if (s.job; as job) {
          <sas-job-progress [job]="job" />
        } @else {
          @if (s.last; as last) {
            <p class="subtle">
              Dernière migration le {{ last.finishedAt | datetime }} :
              {{ last.status === 'failed' ? last.error : last.step }}
            </p>
          }
          @if (s.s3) {
            <div class="row actions">
              <button type="button" class="btn" (click)="confirm.open()" [disabled]="busy()">
                <sas-icon name="refresh" />{{
                  s.active === 's3' ? 'Revenir au disque' : 'Migrer vers S3'
                }}
              </button>
              <span class="hint">{{
                s.active === 's3'
                  ? 'Copie les fichiers du bucket sur le disque, puis bascule.'
                  : 'Copie les fichiers sur le bucket (empreinte vérifiée), puis bascule.'
              }}</span>
            </div>
          }
        }
      </section>

      <form class="card card-body stack" (ngSubmit)="save()">
        <h2>Bucket S3</h2>
        <p class="muted">
          AWS, MinIO, Ceph, Garage… Les fichiers y sont rangés sous leur empreinte SHA-256, vérifiée
          par le serveur à chaque envoi.
          @if (s.active === 's3') {
            Tant que le bucket est actif, seuls l’identifiant et la clé secrète peuvent changer.
          }
        </p>
        @if (formError()) {
          <div class="alert alert-danger">{{ formError() }}</div>
        }
        <div class="grid two">
          <div class="field">
            <label for="s3-endpoint">Point d’accès</label>
            <input
              class="input mono"
              id="s3-endpoint"
              name="endpoint"
              required
              [readonly]="s.active === 's3'"
              [(ngModel)]="form.endpoint"
              (ngModelChange)="tested.set(null)"
              placeholder="https://s3.example.com"
            />
          </div>
          <div class="field">
            <label for="s3-region">Région</label>
            <input
              class="input mono"
              id="s3-region"
              name="region"
              [(ngModel)]="form.region"
              placeholder="us-east-1"
            />
          </div>
        </div>
        <div class="grid two">
          <div class="field">
            <label for="s3-bucket">Bucket</label>
            <input
              class="input mono"
              id="s3-bucket"
              name="bucket"
              required
              [readonly]="s.active === 's3'"
              [(ngModel)]="form.bucket"
              (ngModelChange)="tested.set(null)"
            />
          </div>
          <div class="field">
            <label for="s3-prefix">Préfixe (facultatif)</label>
            <input
              class="input mono"
              id="s3-prefix"
              name="prefix"
              [readonly]="s.active === 's3'"
              [(ngModel)]="form.prefix"
              placeholder="lupa/remus"
            />
          </div>
        </div>
        <div class="grid two">
          <div class="field">
            <label for="s3-access">Identifiant de la clé d’accès</label>
            <input
              class="input mono"
              id="s3-access"
              name="accessKey"
              autocomplete="off"
              required
              [(ngModel)]="form.accessKey"
              (ngModelChange)="tested.set(null)"
            />
          </div>
          <div class="field">
            <label for="s3-secret">Clé secrète</label>
            <input
              class="input mono"
              id="s3-secret"
              name="secretKey"
              type="password"
              autocomplete="new-password"
              [(ngModel)]="secretKey"
              (ngModelChange)="tested.set(null)"
              [placeholder]="s.s3?.hasSecret ? 'Inchangée (enregistrée, chiffrée)' : ''"
            />
          </div>
        </div>
        <label class="check"
          ><input type="checkbox" name="pathStyle" [(ngModel)]="form.pathStyle" /><span
            >Adressage par chemin<br /><span class="subtle"
              >http://hôte/bucket/… : nécessaire pour MinIO et la plupart des serveurs
              auto-hébergés.</span
            ></span
          ></label
        >
        @if (tested(); as t) {
          <div class="alert" [class.alert-ok]="t.ok" [class.alert-danger]="!t.ok">
            {{ t.message }}
          </div>
        }
        <div class="row buttons">
          <button type="button" class="btn btn-ghost" (click)="test()" [disabled]="testing()">
            <sas-icon name="refresh" />{{ testing() ? 'Test…' : 'Tester' }}
          </button>
          <button type="submit" class="btn btn-primary" [disabled]="busy()">Enregistrer</button>
        </div>
      </form>

      <sas-dialog
        #confirm
        [heading]="s.active === 's3' ? 'Revenir au disque ?' : 'Migrer vers S3 ?'"
      >
        <p>
          Chaque fichier est copié puis vérifié ; le stockage ne bascule que si aucun fichier n’a
          échoué. Les fichiers d’origine sont conservés. Pendant la migration, les autres tâches
          attendent leur tour.
        </p>
        <div dialog-actions>
          <button type="button" class="btn" (click)="confirm.close()">Annuler</button>
          <button type="button" class="btn btn-primary" (click)="migrate()">Lancer</button>
        </div>
      </sas-dialog>
    }
  `,
  styles: `
    :host {
      display: grid;
      gap: 16px;
    }
    .kv {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 8px 16px;
      align-items: baseline;
    }
    .kv code {
      overflow-wrap: anywhere;
    }
    .two {
      grid-template-columns: 1fr 1fr;
    }
    .actions,
    .buttons {
      flex-wrap: wrap;
      gap: 12px;
    }
    .buttons {
      justify-content: flex-end;
    }
    @media (max-width: 600px) {
      .two {
        grid-template-columns: 1fr;
      }
    }
  `,
})
export class StorageSettings {
  private readonly http = inject(HttpClient);
  private readonly toasts = inject(Toasts);
  protected readonly confirm = viewChild<Dialog>('confirm');
  protected readonly state = signal<StorageState | null>(null);
  protected readonly error = signal('');
  protected readonly formError = signal('');
  protected readonly busy = signal(false);
  protected readonly testing = signal(false);
  protected readonly tested = signal<{ ok: boolean; message: string } | null>(null);
  protected form: S3Config = {
    endpoint: '',
    region: '',
    bucket: '',
    prefix: '',
    pathStyle: true,
    accessKey: '',
  };
  protected secretKey = '';
  private filled = false;
  private wasActive = false;

  constructor() {
    poll(() => this.refresh());
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.put('/api/storage/s3', this.payload()));
      this.secretKey = '';
      this.formError.set('');
      this.toasts.ok('Paramètres S3 enregistrés.');
      await this.refresh();
    } catch (e) {
      this.formError.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected async test(): Promise<void> {
    this.testing.set(true);
    try {
      this.tested.set(
        await firstValueFrom(
          this.http.post<{ ok: boolean; message: string }>('/api/storage/test', this.payload()),
        ),
      );
    } catch (e) {
      this.tested.set({ ok: false, message: errorMessage(e) });
    } finally {
      this.testing.set(false);
    }
  }

  protected async migrate(): Promise<void> {
    this.confirm()?.close();
    this.busy.set(true);
    try {
      const job = await firstValueFrom(this.http.post<Job>('/api/storage/migrate', {}));
      this.state.update((s) => (s ? { ...s, job } : s));
      this.wasActive = true;
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  private payload(): S3Config & { secretKey: string | null } {
    return { ...this.form, secretKey: this.secretKey.trim() || null };
  }

  private async refresh(): Promise<boolean> {
    try {
      const state = await firstValueFrom(this.http.get<StorageState>('/api/storage'));
      if (this.wasActive && !state.job) {
        if (state.last?.status === 'failed') this.toasts.error('Migration du stockage en échec.');
        else this.toasts.ok('Migration du stockage terminée.');
      }
      this.wasActive = isActive(state.job);
      // Formulaire rempli une fois avec les paramètres enregistrés (pas écrasé pendant la saisie)
      if (!this.filled && state.s3) {
        const { endpoint, region, bucket, prefix, pathStyle, accessKey } = state.s3;
        this.form = { endpoint, region, bucket, prefix, pathStyle, accessKey };
        this.filled = true;
      }
      this.state.set(state);
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
    return this.wasActive;
  }
}
