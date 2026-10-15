import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { DateTimePipe, Eco, EcosystemPipe, Icon, Toasts, errorMessage } from '@sas/shared';
import { Blacklist, BlacklistEntry, CollectorApi } from '../api';
import { BlacklistDialog } from '../blacklist-dialog';

/** Réglages › Liste noire : versions interdites, synchronisées vers Romulus par les bundles. */
@Component({
  selector: 'sas-settings-blacklist',
  imports: [FormsModule, RouterLink, Icon, Eco, EcosystemPipe, DateTimePipe, BlacklistDialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card" id="liste-noire">
      <header class="card-header">
        <div>
          <h2>Liste noire</h2>
          <p class="subtle">Versions interdites : jamais téléchargées ni exportées. Chaque bundle transporte la liste, Romulus met ces versions en quarantaine.</p>
        </div>
        <button type="button" class="btn btn-primary" (click)="dialog.open()"><sas-icon name="plus" />Ajouter</button>
      </header>
      @if (error()) { <div class="card-body"><div class="alert alert-danger">{{ error() }}</div></div> }
      @if (data(); as d) {
        @if (d.pending) {
          <div class="card-body"><div class="alert alert-warn">
            Liste modifiée (révision {{ d.revision }}) depuis le dernier bundle : <a routerLink="/exports">faites un export</a> pour l’appliquer à Romulus, même s’il n’y a aucun nouveau paquet.
          </div></div>
        }
        @if (d.items.length > 3) {
          <div class="card-body filter"><input class="input" type="search" name="q" placeholder="Filtrer…" [ngModel]="q()" (ngModelChange)="q.set($event)" aria-label="Filtrer la liste noire" /></div>
        }
        @if (d.items.length === 0) {
          <div class="empty">
            <sas-icon name="lock" [size]="28" />
            <h3>Aucune version interdite</h3>
            <p>Ajoutez une version ici, ou depuis la page Sécurité ou le contenu d’une liste.</p>
          </div>
        } @else {
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>Paquet</th><th>Version</th><th>Motif</th><th>Collectée</th><th>Ajoutée</th><th></th></tr></thead>
              <tbody>
                @for (e of visible(); track e.id) {
                  <tr>
                    <td><span class="row nowrap"><sas-eco [ecosystem]="e.ecosystem" [size]="22" /><span><strong>{{ e.name }}</strong><br><span class="subtle">{{ e.ecosystem | ecosystem }}</span></span></span></td>
                    <td class="mono">{{ e.version }}</td>
                    <td>{{ e.reason || '—' }}</td>
                    <td>@if (e.collected) { <span class="badge badge-warn" title="Déjà dans le stockage de Rémus : elle n’est plus exportée">oui, écartée</span> } @else { <span class="subtle">non</span> }</td>
                    <td class="subtle nowrap">{{ e.createdAt | datetime }}</td>
                    <td><button type="button" class="btn btn-ghost btn-icon btn-sm" (click)="remove(e)" [disabled]="busy()" [attr.aria-label]="'Retirer ' + e.name + ' ' + e.version" title="Retirer de la liste noire"><sas-icon name="trash" /></button></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <p class="card-body subtle">Révision {{ d.revision }} · dernière transmise à Romulus : {{ d.exportedRevision || 'aucune' }}</p>
        }
      }
    </section>
    <sas-blacklist-dialog #dialog (added)="load()" />
  `,
  styles: `
    .card-header p { margin: 4px 0 0; }
    .filter { padding-bottom: 0; max-width: 360px; }
    .nowrap { white-space: nowrap; }
    td .row { gap: 10px; }
  `,
})
export class SettingsBlacklist implements OnInit {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly data = signal<Blacklist | null>(null);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly q = signal('');
  protected readonly visible = computed(() => {
    const q = this.q().trim().toLowerCase();
    return (this.data()?.items ?? []).filter((e) => !q || `${e.name} ${e.version} ${e.reason}`.toLowerCase().includes(q));
  });

  ngOnInit(): void {
    void this.load();
  }

  protected async load(): Promise<void> {
    try {
      this.data.set(await this.api.blacklist());
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async remove(entry: BlacklistEntry): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.removeBlacklist(entry.id);
      this.toasts.ok(`${entry.name} ${entry.version} retiré de la liste noire.`);
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
