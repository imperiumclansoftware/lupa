import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { BytesPipe, ECOSYSTEMS, EcosystemPipe, Icon, PluralPipe, errorMessage, Eco } from '@sas/shared';
import { Artifact, CollectorApi, Page } from '../api';

@Component({
  imports: [Eco, FormsModule, Icon, BytesPipe, EcosystemPipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Paquets</h1>
          <p>Tout ce que le collecteur a téléchargé, toutes listes confondues.</p>
        </div>
      </header>

      <div class="toolbar">
        <div class="tabs" role="tablist" aria-label="Écosystème">
          <button type="button" role="tab" [attr.aria-selected]="!ecosystem()" (click)="setEcosystem('')">Tous</button>
          @for (eco of ecosystems; track eco.id) {
            <button type="button" role="tab" [attr.aria-selected]="ecosystem() === eco.id" (click)="setEcosystem(eco.id)">
              <sas-eco [ecosystem]="eco.id" [size]="22" />{{ eco.label }}
            </button>
          }
        </div>
        <div class="row">
          <label class="visually-hidden" for="q">Rechercher</label>
          <input class="input" id="q" type="search" placeholder="Rechercher un nom…" [ngModel]="q()" (ngModelChange)="search($event)" />
          <label class="check"><input type="checkbox" [ngModel]="pending()" (ngModelChange)="pending.set($event); load(1)" /> À exporter</label>
        </div>
      </div>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      <section class="card">
        @if (data(); as d) {
          @if (d.items.length === 0) {
            <div class="empty"><sas-icon name="search" [size]="28" /><h3>Aucun paquet</h3></div>
          } @else {
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Nom</th><th>Version</th><th>Écosystème</th><th>Détail</th><th class="right">Taille</th><th>Export</th></tr></thead>
                <tbody>
                  @for (a of d.items; track a.id) {
                    <tr>
                      <td><strong class="name">{{ a.name }}</strong>@if (a.filename) { <div class="subtle mono">{{ a.filename }}</div> }</td>
                      <td class="mono">{{ a.version }}</td>
                      <td><span class="row"><sas-eco [ecosystem]="a.ecosystem" [size]="26" />{{ a.ecosystem | ecosystem }}</span></td>
                      <td class="detail">{{ a.detail }}</td>
                      <td class="right num">{{ a.size | bytes }}</td>
                      <td>@if (a.exportSequence) { <span class="badge">n° {{ a.exportSequence }}</span> } @else { <span class="badge badge-accent">À exporter</span> }</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <footer class="pager">
              <span class="subtle">{{ d.total | plural: 'résultat' }}</span>
              <div class="row">
                <button type="button" class="btn btn-sm" [disabled]="d.page <= 1" (click)="load(d.page - 1)">Précédent</button>
                <span class="subtle num">Page {{ d.page }} / {{ pages(d) }}</span>
                <button type="button" class="btn btn-sm" [disabled]="d.page >= pages(d)" (click)="load(d.page + 1)">Suivant</button>
              </div>
            </footer>
          }
        }
      </section>
    </div>
  `,
  styles: `
    .toolbar { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; }
    .toolbar .input { width: 240px; }
    .name { overflow-wrap: anywhere; }
    .detail { color: var(--text-2); font-size: 0.86rem; max-width: 360px; overflow-wrap: anywhere; }
    .pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
  `,
})
export default class Artifacts implements OnInit {
  /** Filtre initial depuis l'URL (?ecosystem=npm) */
  readonly ecosystemParam = input<string>('', { alias: 'ecosystem' });
  private readonly api = inject(CollectorApi);
  private readonly router = inject(Router);
  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly ecosystem = signal('');
  protected readonly q = signal('');
  protected readonly pending = signal(false);
  protected readonly data = signal<Page<Artifact> | null>(null);
  protected readonly error = signal('');
  private debounce?: ReturnType<typeof setTimeout>;

  ngOnInit(): void {
    this.ecosystem.set(this.ecosystemParam() ?? '');
    void this.load(1);
  }

  protected pages(d: Page<Artifact>): number {
    return Math.max(1, Math.ceil(d.total / d.pageSize));
  }

  protected setEcosystem(id: string): void {
    this.ecosystem.set(id);
    void this.router.navigate([], { queryParams: { ecosystem: id || null }, replaceUrl: true });
    void this.load(1);
  }

  protected search(value: string): void {
    this.q.set(value);
    clearTimeout(this.debounce);
    this.debounce = setTimeout(() => void this.load(1), 250);
  }

  protected async load(page: number): Promise<void> {
    try {
      this.data.set(await this.api.artifacts({ ecosystem: this.ecosystem(), q: this.q(), pending: this.pending(), page }));
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
