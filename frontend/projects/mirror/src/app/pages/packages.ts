import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { BytesPipe, SeverityBadge, ECOSYSTEMS, EcosystemPipe, Icon, PluralPipe, RelativePipe, errorMessage, Eco } from '@sas/shared';
import { MirrorApi, Page, PackageSummary } from '../api';

@Component({
  imports: [Eco, SeverityBadge, FormsModule, RouterLink, Icon, BytesPipe, EcosystemPipe, RelativePipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Paquets</h1>
          <p>Ce que le miroir peut servir aux postes du réseau isolé.</p>
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
        <label class="visually-hidden" for="q">Rechercher</label>
        <input class="input search" id="q" type="search" placeholder="Rechercher un nom…" [ngModel]="q()" (ngModelChange)="search($event)" />
      </div>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      <section class="card">
        @if (data(); as d) {
          @if (d.items.length === 0) {
            <div class="empty"><sas-icon name="package" [size]="28" /><h3>Aucun paquet</h3><p>Importez un bundle pour remplir le miroir.</p></div>
          } @else {
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Nom</th><th>Écosystème</th><th class="right">Versions</th><th class="right">Taille</th><th>Sécurité</th><th>Mis à jour</th></tr></thead>
                <tbody>
                  @for (p of d.items; track p.ecosystem + p.name) {
                    <tr>
                      <td><a class="row-link name" [routerLink]="['/paquets', p.ecosystem, p.name]">{{ p.name }}</a></td>
                      <td><span class="row"><sas-eco [ecosystem]="p.ecosystem" [size]="26" />{{ p.ecosystem | ecosystem }}</span></td>
                      <td class="right num">{{ p.versions }}@if (p.files !== p.versions) { <span class="subtle"> · {{ p.files }} fichiers</span> }</td>
                      <td class="right num">{{ p.size | bytes }}</td>
                      <td><sas-severity [severity]="p.severity" />@if (p.quarantined) { <span class="badge badge-danger" title="Versions de la liste noire, plus servies par le registre">{{ p.quarantined }} en quarantaine</span> }</td>
                      <td class="subtle">{{ p.updatedAt | relative }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <footer class="pager">
              <span class="subtle">{{ d.total | plural: 'paquet' }}</span>
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
    .search { width: 260px; }
    .name { overflow-wrap: anywhere; }
    .pager { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--border); flex-wrap: wrap; }
  `,
})
export default class Packages implements OnInit {
  readonly ecosystemParam = input<string>('', { alias: 'ecosystem' });
  private readonly api = inject(MirrorApi);
  private readonly router = inject(Router);
  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly ecosystem = signal('');
  protected readonly q = signal('');
  protected readonly data = signal<Page<PackageSummary> | null>(null);
  protected readonly error = signal('');
  private debounce?: ReturnType<typeof setTimeout>;

  ngOnInit(): void {
    this.ecosystem.set(this.ecosystemParam() ?? '');
    void this.load(1);
  }

  protected pages(d: Page<PackageSummary>): number {
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
      this.data.set(await this.api.packages({ ecosystem: this.ecosystem(), q: this.q(), page }));
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
