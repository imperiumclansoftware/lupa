import { ChangeDetectionStrategy, Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { EcosystemId, Icon, errorMessage } from '@sas/shared';
import { CollectorApi, SearchResult } from './api';

type Mode = 'exact' | 'compatible' | 'minimum' | 'latest';

/** Formes proposées selon l'écosystème, de la plus stricte à la plus souple. */
const MODES: Record<EcosystemId, { id: Mode; label: string; build: (name: string, v: string) => string }[]> = {
  npm: [
    { id: 'compatible', label: 'Compatible (^)', build: (n, v) => `${n}@^${v}` },
    { id: 'exact', label: 'Exacte', build: (n, v) => `${n}@${v}` },
    { id: 'minimum', label: 'Correctifs (~)', build: (n, v) => `${n}@~${v}` },
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
  ],
  pypi: [
    { id: 'exact', label: 'Exacte (==)', build: (n, v) => `${n}==${v}` },
    { id: 'compatible', label: 'Compatible (~=)', build: (n, v) => `${n}~=${v}` },
    { id: 'minimum', label: 'Minimum (>=)', build: (n, v) => `${n}>=${v}` },
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
  ],
  composer: [
    { id: 'compatible', label: 'Compatible (^)', build: (n, v) => `${n}:^${v.replace(/^v/, '')}` },
    { id: 'exact', label: 'Exacte', build: (n, v) => `${n}:${v}` },
    { id: 'minimum', label: 'Correctifs (~)', build: (n, v) => `${n}:~${v.replace(/^v/, '')}` },
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
  ],
  docker: [{ id: 'exact', label: 'Étiquette', build: (n, v) => `${n}:${v}` }],
  galaxy: [
    { id: 'compatible', label: 'Majeure (>=x,<x+1)', build: (n, v) => `${n} >=${v},<${(parseInt(v, 10) || 0) + 1}.0.0` },
    { id: 'exact', label: 'Exacte', build: (n, v) => `${n} ${v}` },
    { id: 'minimum', label: 'Minimum (>=)', build: (n, v) => `${n} >=${v}` },
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
  ],
  terraform: [
    { id: 'compatible', label: 'Compatible (~>)', build: (n, v) => `${n} ~> ${v.split('.').slice(0, 2).join('.')}` },
    { id: 'exact', label: 'Exacte', build: (n, v) => `${n} ${v}` },
    { id: 'minimum', label: 'Minimum (>=)', build: (n, v) => `${n} >= ${v}` },
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
  ],
  deb: [
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
    { id: 'exact', label: 'Exacte (=)', build: (n, v) => `${n}=${v}` },
  ],
  rpm: [
    { id: 'latest', label: 'Toujours la dernière', build: (n) => n },
    { id: 'exact', label: 'Exacte (=)', build: (n, v) => `${n}=${v}` },
  ],
};

/**
 * Recherche dans le registre de l'écosystème, choix d'une version et de la contrainte,
 * ou aperçu d'un motif (« * », « ? ») avant de l'ajouter à la liste.
 */
@Component({
  selector: 'app-package-picker',
  imports: [FormsModule, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="picker">
      <div class="search">
        <sas-icon name="search" />
        <label class="visually-hidden" for="pkg-search">Rechercher dans le registre</label>
        <input id="pkg-search" class="input" type="search" autocomplete="off" spellcheck="false"
          [placeholder]="placeholder()" [ngModel]="query()" (ngModelChange)="onQuery($event)" (keydown.enter)="$event.preventDefault()" />
        @if (busy()) { <span class="spinner" aria-label="Recherche…"></span> }
      </div>
      <p class="hint">{{ hint() }}</p>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (isPattern()) {
        <div class="panel">
          @if (preview(); as p) {
            <div class="row head">
              <strong class="grow">{{ p.length }} correspondance{{ p.length > 1 ? 's' : '' }}</strong>
              <button type="button" class="btn btn-sm" (click)="emit([query().trim()])" title="Le motif est conservé : les nouveaux paquets seront ajoutés à chaque mise à jour">Ajouter le motif</button>
              <button type="button" class="btn btn-sm btn-primary" (click)="emit(p)">Ajouter les {{ p.length }} lignes</button>
            </div>
            <ul class="matches">
              @for (line of p.slice(0, 60); track line) { <li class="mono">{{ line }}</li> }
              @if (p.length > 60) { <li class="subtle">… et {{ p.length - 60 }} autres</li> }
            </ul>
            @if (skipped().length) {
              <details class="skipped">
                <summary>{{ skipped().length }} écarté{{ skipped().length > 1 ? 's' : '' }} : aucune version ne respecte la contrainte</summary>
                <p class="mono">{{ skipped().join(', ') }}</p>
              </details>
            }
          } @else {
            <button type="button" class="btn btn-sm" (click)="expand()" [disabled]="busy()"><sas-icon name="search" [size]="15" />Voir les paquets correspondants</button>
          }
        </div>
      } @else if (selected(); as s) {
        <div class="panel">
          <div class="row head">
            <strong class="grow name">{{ s.name }}</strong>
            <button type="button" class="btn btn-sm btn-ghost" (click)="selected.set(null)" aria-label="Revenir aux résultats"><sas-icon name="x" [size]="15" /></button>
          </div>
          @if (s.description) { <p class="muted desc">{{ s.description }}</p> }
          <div class="choose">
            <div class="field">
              <label for="pkg-version">{{ ecosystem() === 'docker' ? 'Étiquette' : 'Version' }}</label>
              <select id="pkg-version" class="select" [ngModel]="version()" (ngModelChange)="version.set($event)" [disabled]="!versions().length">
                @for (v of versions(); track v) { <option [value]="v">{{ v }}{{ v === latest() ? ' (dernière)' : '' }}</option> }
              </select>
            </div>
            @if (modes().length > 1) {
              <div class="field">
                <span class="label">Contrainte</span>
                <div class="chips">
                  @for (m of modes(); track m.id) {
                    <label class="chip"><input type="radio" name="pkg-mode" [checked]="mode() === m.id" (change)="mode.set(m.id)" />{{ m.label }}</label>
                  }
                </div>
              </div>
            }
          </div>
          <div class="row add">
            <code class="grow">{{ line() }}</code>
            <button type="button" class="btn btn-primary btn-sm" (click)="emit([line()])" [disabled]="!line()"><sas-icon name="plus" [size]="15" />Ajouter</button>
          </div>
        </div>
      } @else if (results().length) {
        <ul class="results" role="listbox" aria-label="Résultats">
          @for (r of results(); track r.name) {
            <li>
              <button type="button" (click)="choose(r)">
                <span class="row"><strong class="name">{{ r.name }}</strong>
                  @if (r.version) { <span class="badge">{{ r.version }}</span> }
                  @if (r.info) { <span class="subtle">{{ r.info }}</span> }</span>
                @if (r.description) { <span class="subtle desc">{{ r.description }}</span> }
              </button>
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: `
    .picker { display: grid; gap: 8px; }
    .search { position: relative; display: flex; align-items: center; }
    .search > sas-icon { position: absolute; left: 12px; color: var(--text-3); pointer-events: none; }
    .search .input { padding-left: 38px; }
    .spinner { position: absolute; right: 12px; width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--border-strong); border-top-color: var(--accent); animation: spin 700ms linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .hint { font-size: 0.82rem; color: var(--text-3); margin: 0; }
    .results { list-style: none; margin: 0; padding: 4px; border-radius: 12px; background: var(--surface-2); box-shadow: inset 0 0 0 1px var(--border); max-height: 340px; overflow: auto; }
    .results button { display: grid; gap: 2px; width: 100%; padding: 8px 10px; border: none; border-radius: 8px; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .results button:hover, .results button:focus-visible { background: var(--accent-soft); }
    .name { overflow-wrap: anywhere; }
    .desc { font-size: 0.82rem; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .panel { display: grid; gap: 10px; padding: 14px; border-radius: 12px; background: var(--surface-2); box-shadow: inset 0 0 0 1px var(--border); }
    .head { align-items: center; }
    .grow { flex: 1; min-width: 0; }
    .choose { display: grid; gap: 10px; grid-template-columns: minmax(160px, 220px) 1fr; align-items: start; }
    .add code { padding: 6px 10px; border-radius: 8px; background: var(--surface); overflow-wrap: anywhere; }
    .skipped { font-size: 0.82rem; color: var(--text-2); }
    .skipped summary { cursor: pointer; }
    .skipped p { margin: 6px 0 0; overflow-wrap: anywhere; }
    .matches { margin: 0; padding: 0 0 0 4px; list-style: none; max-height: 220px; overflow: auto; display: grid; gap: 2px; font-size: 0.82rem; }
    @media (max-width: 640px) { .choose { grid-template-columns: 1fr; } }
  `,
})
export class PackagePicker {
  readonly ecosystem = input.required<EcosystemId>();
  /** Debian, RPM : distribution dont l'index est consulté */
  readonly distribution = input<string | null>(null);
  readonly add = output<string[]>();
  private readonly api = inject(CollectorApi);

  protected readonly query = signal('');
  protected readonly results = signal<SearchResult[]>([]);
  protected readonly selected = signal<SearchResult | null>(null);
  protected readonly versions = signal<string[]>([]);
  protected readonly latest = signal<string | null>(null);
  protected readonly version = signal('');
  protected readonly mode = signal<Mode>('compatible');
  protected readonly preview = signal<string[] | null>(null);
  protected readonly skipped = signal<string[]>([]);
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected readonly isPattern = computed(() => /[*?]/.test(this.query()));
  protected readonly modes = computed(() => MODES[this.ecosystem()]);
  protected readonly line = computed(() => {
    const s = this.selected();
    const mode = this.modes().find((m) => m.id === this.mode()) ?? this.modes()[0];
    return s && (this.version() || mode.id === 'latest') ? mode.build(s.name, this.version()) : '';
  });
  protected readonly placeholder = computed(() => ({
    npm: 'Rechercher un paquet npm, ou un motif : @syncfusion/ej2-*angular*@^29',
    pypi: 'Rechercher un paquet Python, ou un motif : django-rest-*',
    composer: 'Rechercher un paquet Composer, ou un motif : symfony/*-bundle:^7.3',
    docker: 'Rechercher une image, ou un motif : node:24-alpine*, bitnami/*-exporter',
    deb: 'Rechercher un paquet Debian, ou un motif : python3-django*',
    galaxy: 'Rechercher une collection ou un rôle Ansible : docker, community.general',
    terraform: 'Rechercher un provider ou un module Terraform : aws, vpc, « terraform » pour le binaire',
    rpm: 'Rechercher un paquet RPM, ou un motif : perl-*',
  })[this.ecosystem()]);
  protected readonly hint = computed(() =>
    this.isPattern()
      ? '« * » remplace n’importe quelle suite de caractères, « ? » un seul. Un motif ajouté tel quel est redéveloppé à chaque mise à jour de la liste.'
      : 'Choisissez un résultat pour voir ses versions. Les dépendances sont ajoutées automatiquement lors de la mise à jour.',
  );

  private timer?: ReturnType<typeof setTimeout>;
  private request = 0;

  constructor() {
    // Changement d'écosystème : on repart de zéro
    effect(() => {
      this.ecosystem();
      this.distribution();
      untracked(() => this.reset());
    });
  }

  protected onQuery(value: string): void {
    this.query.set(value);
    this.selected.set(null);
    this.preview.set(null);
    this.error.set('');
    clearTimeout(this.timer);
    if (this.isPattern() || value.trim().length < 2) {
      this.results.set([]);
      return;
    }
    this.timer = setTimeout(() => void this.run(), 350);
  }

  private async run(): Promise<void> {
    const id = ++this.request;
    this.busy.set(true);
    try {
      const results = await this.api.search(this.ecosystem(), this.query().trim(), this.distribution());
      if (id === this.request) this.results.set(results);
    } catch (e) {
      if (id === this.request) this.error.set(errorMessage(e));
    } finally {
      if (id === this.request) this.busy.set(false);
    }
  }

  protected async choose(result: SearchResult): Promise<void> {
    this.selected.set(result);
    this.versions.set([]);
    this.version.set('');
    this.mode.set(this.modes()[0].id);
    this.busy.set(true);
    try {
      const data = await this.api.versions(this.ecosystem(), result.name, this.distribution());
      this.versions.set(data.versions);
      this.latest.set(data.latest);
      this.version.set(data.latest ?? data.versions[0] ?? '');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected async expand(): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      const result = await this.api.expand(this.ecosystem(), this.query().trim(), this.distribution());
      this.preview.set(result.lines);
      this.skipped.set(result.skipped);
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected emit(lines: string[]): void {
    this.add.emit(lines);
    this.reset();
  }

  private reset(): void {
    this.query.set('');
    this.results.set([]);
    this.selected.set(null);
    this.preview.set(null);
    this.error.set('');
  }
}
