import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  DateTimePipe,
  Eco,
  EcosystemPipe,
  Icon,
  JobProgress,
  RelativePipe,
  SEVERITY_LABELS,
  SeverityBadge,
  Toasts,
  errorMessage,
  isActive,
  poll,
  Auth,
} from '@sas/shared';
import { AlertLevel, CollectorApi, SecurityItem, SecurityReport } from '../api';
import { BlacklistDialog } from '../blacklist-dialog';

@Component({
  imports: [
    BlacklistDialog,
    RouterLink,
    Icon,
    Eco,
    SeverityBadge,
    JobProgress,
    RelativePipe,
    DateTimePipe,
    EcosystemPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Sécurité</h1>
          <p>
            Vulnérabilités connues des paquets npm, Python et Composer collectés, d’après OSV.dev
            (avis GitHub, PyPA, npm, Packagist). Les alertes voyagent dans les bundles : Romulus les
            affiche aussi sur le réseau isolé.
          </p>
        </div>
        @if (auth.can('operator')) {
          <button
            data-tour="analyse"
            type="button"
            class="btn btn-primary"
            (click)="audit()"
            [disabled]="!!data()?.job || busy()"
          >
            <sas-icon name="refresh" />Analyser maintenant
          </button>
        }
      </header>

      @if (error()) {
        <div class="alert alert-danger">{{ error() }}</div>
      }

      @if (data(); as d) {
        @if (d.job) {
          <section class="card card-body"><sas-job-progress [job]="d.job" /></section>
        }

        <section data-tour="alertes" class="grid stats">
          <button
            type="button"
            class="card stat critical"
            [class.active]="filter() === 'critical'"
            (click)="setFilter('critical')"
          >
            <span class="stat-label">Critiques</span
            ><span class="stat-value">{{ d.counts.critical }}</span
            ><span class="subtle">paquets à remplacer</span>
          </button>
          <button
            type="button"
            class="card stat warning"
            [class.active]="filter() === 'warning'"
            (click)="setFilter('warning')"
          >
            <span class="stat-label">Avertissements</span
            ><span class="stat-value">{{ d.counts.warning }}</span
            ><span class="subtle">gravité élevée ou moyenne</span>
          </button>
          <button
            type="button"
            class="card stat"
            [class.active]="filter() === 'low'"
            (click)="setFilter('low')"
          >
            <span class="stat-label">Faibles</span><span class="stat-value">{{ d.counts.low }}</span
            ><span class="subtle">pour information</span>
          </button>
          <div class="card stat">
            <span class="stat-label">Paquets analysés</span
            ><span class="stat-value"
              >{{ d.audited }}<small> / {{ d.auditable }}</small></span
            >
            <span class="subtle" [title]="d.oldestAudit | datetime">{{
              d.oldestAudit
                ? 'analyse la plus ancienne ' + (d.oldestAudit | relative)
                : 'jamais analysés'
            }}</span>
          </div>
        </section>

        <section data-tour="vulnerables" class="card">
          <header class="card-header">
            <h2>{{ filter() === 'all' ? 'Paquets vulnérables' : filterLabel() }}</h2>
            @if (filter() !== 'all') {
              <button type="button" class="btn btn-sm btn-ghost" (click)="setFilter('all')">
                Tout afficher
              </button>
            }
          </header>
          @if (d.items.length === 0) {
            <div class="empty">
              <sas-icon name="shield" [size]="28" />
              <h3>
                {{
                  d.counts.critical + d.counts.warning + d.counts.low === 0
                    ? 'Aucune vulnérabilité connue'
                    : 'Rien dans cette catégorie'
                }}
              </h3>
              @if (d.audited < d.auditable) {
                <p>
                  {{ d.auditable - d.audited }} paquet(s) n’ont pas encore été analysés : lancez une
                  analyse.
                </p>
              }
            </div>
          } @else {
            <ul class="items">
              @for (item of d.items; track item.id) {
                <li>
                  <details>
                    <summary>
                      <sas-eco [ecosystem]="item.ecosystem" [size]="28" />
                      <span class="what">
                        <strong>{{ item.name }}</strong>
                        <span class="mono version">{{ item.version }}</span>
                        <span class="subtle">
                          · {{ item.ecosystem | ecosystem }}
                          @for (l of item.lists; track l.id; let first = $first) {
                            {{ first ? ' · ' : ', '
                            }}<a
                              [routerLink]="['/listes', l.id]"
                              (click)="$event.stopPropagation()"
                              >{{ l.name }}</a
                            >
                          }
                        </span>
                      </span>
                      <span class="fix">
                        @if (fixHint(item); as f) {
                          Corrigé en <strong class="mono">{{ f }}</strong>
                        }
                      </span>
                      @if (item.blacklisted) {
                        <span class="badge badge-danger" [title]="item.blacklisted.reason"
                          >Liste noire</span
                        >
                      } @else {
                        @if (auth.can('admin')) {
                          <button
                            type="button"
                            class="btn btn-ghost btn-sm ban"
                            (click)="ban($event, item)"
                            title="Interdire cette version : plus téléchargée, plus exportée, quarantaine dans Romulus"
                          >
                            <sas-icon name="lock" />Interdire
                          </button>
                        }
                      }
                      <sas-severity [severity]="item.severity" [count]="item.vulnerabilityCount" />
                      <sas-icon class="chev" name="chevron" [size]="16" />
                    </summary>
                    <table class="table vulns">
                      <thead>
                        <tr>
                          <th>Vulnérabilité</th>
                          <th>Gravité</th>
                          <th>Corrigée en</th>
                        </tr>
                      </thead>
                      <tbody>
                        @for (v of item.vulnerabilities; track v.id) {
                          <tr>
                            <td>
                              <a
                                [href]="v.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mono"
                                >{{ v.id }}</a
                              >
                              @if (v.aliases.length) {
                                <span class="subtle mono"> · {{ v.aliases.join(', ') }}</span>
                              }
                              <div>{{ v.summary }}</div>
                            </td>
                            <td><sas-severity [severity]="v.severity" /></td>
                            <td class="mono">{{ v.fixed.length ? v.fixed.join(', ') : '—' }}</td>
                          </tr>
                        }
                      </tbody>
                    </table>
                  </details>
                </li>
              }
            </ul>
            @if (d.items.length < d.total) {
              <div class="more">
                <button type="button" class="btn btn-sm" (click)="more()">
                  Afficher plus ({{ d.total - d.items.length }} restants)
                </button>
              </div>
            }
          }
        </section>
        <p class="subtle">
          Les images Docker ne sont pas analysées : leurs failles se trouvent dans les paquets du
          système, qui demandent un scanner d’images dédié.
        </p>
      }
    </div>
    <sas-blacklist-dialog #banDialog (added)="refresh()" />
  `,
  styles: `
    .ban {
      white-space: nowrap;
    }
    .more {
      display: flex;
      justify-content: center;
      padding: 14px;
      border-top: 1px solid var(--border);
    }
    .stats {
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    }
    button.stat {
      border: none;
      text-align: left;
      font: inherit;
      color: inherit;
      cursor: pointer;
      transition-property: box-shadow, transform;
      transition-duration: 160ms;
    }
    button.stat:hover {
      box-shadow: var(--shadow-hover);
      transform: translateY(-1px);
    }
    .stat.critical .stat-value {
      color: var(--danger);
    }
    .stat.warning .stat-value {
      color: var(--warn);
    }
    .stat.active {
      box-shadow:
        inset 0 0 0 2px var(--accent),
        var(--shadow-card);
    }
    .stat small {
      font-size: 1rem;
      color: var(--text-3);
      font-weight: 600;
    }
    .items {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .items > li {
      border-top: 1px solid var(--border);
    }
    summary {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 24px;
      cursor: pointer;
      list-style: none;
    }
    summary::-webkit-details-marker {
      display: none;
    }
    summary:hover {
      background: color-mix(in oklab, var(--accent-soft) 45%, transparent);
    }
    .what {
      flex: 1;
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .what .version {
      margin-left: 6px;
    }
    .fix {
      font-size: 0.84rem;
      color: var(--text-2);
      white-space: nowrap;
    }
    .chev {
      color: var(--text-3);
      transition: transform 160ms var(--ease);
    }
    details[open] .chev {
      transform: rotate(90deg);
    }
    .vulns {
      font-size: 0.86rem;
    }
    .vulns td:first-child {
      max-width: 560px;
    }
    @media (max-width: 700px) {
      .fix {
        display: none;
      }
    }
  `,
})
export default class SecurityPage {
  protected readonly auth = inject(Auth);
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly data = signal<SecurityReport | null>(null);
  protected readonly filter = signal<AlertLevel>('all');
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly filterLabel = computed(
    () =>
      ({ all: '', critical: 'Paquets critiques', warning: 'Avertissements', low: 'Faibles' })[
        this.filter()
      ],
  );
  private wasActive = false;

  constructor() {
    poll(async () => {
      try {
        const data = await this.api.security(this.filter());
        if (this.wasActive && !data.job) this.toasts.ok('Analyse de sécurité terminée.');
        this.wasActive = isActive(data.job);
        this.data.set(data);
        this.error.set('');
      } catch (e) {
        this.error.set(errorMessage(e));
      }
      return this.wasActive;
    });
  }

  private readonly banDialog = viewChild.required<BlacklistDialog>('banDialog');

  protected ban(event: Event, item: SecurityItem): void {
    event.preventDefault();
    event.stopPropagation();
    this.banDialog().open({
      ecosystem: item.ecosystem,
      name: item.name,
      version: item.version,
      reason: item.vulnerabilities
        .map((v) => v.id)
        .slice(0, 3)
        .join(', '),
    });
  }

  protected async refresh(): Promise<void> {
    try {
      this.data.set(await this.api.security(this.filter()));
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  /** Page suivante, ajoutée à la suite de celles déjà affichées */
  protected async more(): Promise<void> {
    const current = this.data();
    if (!current) return;
    try {
      const next = await this.api.security(this.filter(), current.page + 1);
      this.data.set({ ...next, items: [...current.items, ...next.items] });
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected setFilter(filter: AlertLevel): void {
    this.filter.set(this.filter() === filter ? 'all' : filter);
    void this.refresh();
  }

  /** Plus petite version corrigée proposée par les avis (indication) */
  protected fixHint(item: SecurityItem): string | null {
    const fixed = [...new Set(item.vulnerabilities.flatMap((v) => v.fixed))];
    return fixed.length ? fixed.slice(0, 3).join(', ') + (fixed.length > 3 ? '…' : '') : null;
  }

  protected async audit(): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.audit();
      this.wasActive = true;
      this.data.set(await this.api.security(this.filter()));
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected readonly labels = SEVERITY_LABELS;
}
