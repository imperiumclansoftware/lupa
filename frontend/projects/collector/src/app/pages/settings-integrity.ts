import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  Auth,
  BytesPipe,
  DateTimePipe,
  Eco,
  EcosystemPipe,
  Icon,
  JobProgress,
  PluralPipe,
  Toasts,
  errorMessage,
  isActive,
  poll,
} from '@sas/shared';
import { CollectorApi, IntegrityItem, IntegrityState } from '../api';

const PROBLEMS: Record<IntegrityItem['files'][number]['problem'], string> = {
  missing: 'absent du stockage',
  size: 'taille incorrecte',
  hash: 'contenu modifié',
};

/** Réglages › Intégrité : vérifie les empreintes du stockage et retélécharge les paquets abîmés. */
@Component({
  selector: 'sas-settings-integrity',
  imports: [RouterLink, BytesPipe, DateTimePipe, Eco, EcosystemPipe, Icon, JobProgress, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card card-body stack">
      <div class="row head">
        <div class="row">
          <sas-icon name="hdd" />
          <h2>Intégrité des paquets</h2>
        </div>
        @if (auth.can('operator')) {
          <button class="btn" type="button" (click)="check()" [disabled]="busy() || running()">
            <sas-icon name="refresh" />Vérifier l’intégrité
          </button>
        }
      </div>
      <p class="muted">
        Recalcule l’empreinte sha256 de chaque fichier du stockage. Un fichier absent ou abîmé est
        mis de côté, puis les listes qui contiennent le paquet sont mises à jour pour le
        retélécharger (ce qui peut aussi y prendre de nouvelles versions). Les paquets qui n’ont pas
        pu être corrigés sont signalés en erreur.
      </p>

      @if (error()) {
        <div class="alert alert-danger">{{ error() }}</div>
      }

      @if (state()?.job; as job) {
        <sas-job-progress [job]="job" />
      } @else if (state()?.last; as last) {
        @if (last.status === 'failed') {
          <div class="alert alert-danger">
            Vérification du {{ last.finishedAt | datetime }} en échec : {{ last.error }}
          </div>
        } @else if (last.report; as r) {
          <p class="summary">
            Dernière vérification le {{ last.finishedAt | datetime }} : {{ r.files }}
            {{ r.files | plural: 'fichier' }} ({{ r.bytes | bytes }}) pour {{ r.artifacts }}
            {{ r.artifacts | plural: 'paquet' }}.
          </p>
          @if (r.damaged === 0) {
            <div class="alert alert-ok">
              <sas-icon name="check" />Tous les fichiers sont intègres.
            </div>
          } @else {
            <div class="row counts">
              <span class="badge">{{ r.damaged }} non {{ r.damaged | plural: 'intègre' }}</span>
              <span class="badge badge-ok"
                >{{ r.repaired }} {{ r.repaired | plural: 'corrigé' }}</span
              >
              @if (r.failed) {
                <span class="badge badge-danger">{{ r.failed }} en erreur</span>
              }
            </div>
            <table class="table">
              <thead>
                <tr>
                  <th>Paquet</th>
                  <th>Problème</th>
                  <th>Résultat</th>
                </tr>
              </thead>
              <tbody>
                @for (item of r.items; track item.id) {
                  <tr>
                    <td>
                      <div class="row pkg">
                        <sas-eco [ecosystem]="item.ecosystem" [size]="24" />
                        <div>
                          <strong>{{ item.name }}</strong>
                          <span class="mono"> {{ item.version }}</span>
                          <div class="subtle">
                            {{ item.ecosystem | ecosystem }}
                            @if (item.filename) {
                              · <span class="mono">{{ item.filename }}</span>
                            }
                          </div>
                        </div>
                      </div>
                    </td>
                    <td>
                      @for (f of item.files; track f.sha256) {
                        <div>
                          {{ problem(f.problem) }}
                          <span class="subtle mono" [title]="f.sha256">{{
                            f.sha256.slice(0, 12)
                          }}</span>
                        </div>
                      }
                    </td>
                    <td>
                      @if (item.status === 'repaired') {
                        <span class="badge badge-ok">Corrigé</span>
                      } @else {
                        <span class="badge badge-danger">Erreur</span>
                        <div class="message">{{ item.message }}</div>
                      }
                      @if (item.lists.length) {
                        <div class="subtle">
                          @for (l of item.lists; track l.id; let last = $last) {
                            <a [routerLink]="['/listes', l.id]">{{ l.name }}</a
                            >{{ last ? '' : ', ' }}
                          }
                        </div>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
            @if (r.truncated) {
              <p class="subtle">Rapport limité aux {{ r.items.length }} premiers paquets.</p>
            }
          }
        }
      } @else if (state()) {
        <p class="subtle">Aucune vérification lancée pour l’instant.</p>
      }
    </section>
  `,
  styles: `
    .head {
      justify-content: space-between;
      flex-wrap: wrap;
    }
    .counts {
      flex-wrap: wrap;
      gap: 8px;
    }
    .pkg {
      align-items: flex-start;
      gap: 10px;
    }
    .message {
      margin-top: 4px;
      overflow-wrap: anywhere;
    }
    .table td {
      vertical-align: top;
    }
  `,
})
export class SettingsIntegrity {
  protected readonly auth = inject(Auth);
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly state = signal<IntegrityState | null>(null);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  private wasActive = false;

  constructor() {
    poll(() => this.refresh());
  }

  protected running(): boolean {
    return isActive(this.state()?.job);
  }

  protected problem(p: IntegrityItem['files'][number]['problem']): string {
    return PROBLEMS[p];
  }

  protected async check(): Promise<void> {
    this.busy.set(true);
    try {
      const job = await this.api.checkIntegrity();
      this.state.update((s) => ({ job, last: s?.last ?? null }));
      this.wasActive = true;
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  private async refresh(): Promise<boolean> {
    try {
      const state = await this.api.integrity();
      if (this.wasActive && !state.job) {
        const r = state.last?.report;
        if (state.last?.status === 'failed')
          this.toasts.error('Vérification d’intégrité en échec.');
        else if (r?.failed) this.toasts.error(`Intégrité : ${r.failed} paquet(s) non corrigé(s).`);
        else this.toasts.ok('Vérification d’intégrité terminée.');
      }
      this.wasActive = isActive(state.job);
      this.state.set(state);
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
    return this.wasActive;
  }
}
