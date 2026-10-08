import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { BytesPipe, Dialog, EcosystemPipe, Icon, JobProgress, PluralPipe, RelativePipe, Toasts, errorMessage, isActive, poll, Eco } from '@sas/shared';
import { Artifact, CollectorApi, PackageList } from '../api';

@Component({
  imports: [Eco, FormsModule, RouterLink, Icon, JobProgress, Dialog, BytesPipe, RelativePipe, EcosystemPipe, PluralPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      @if (list(); as l) {
        <header class="page-header">
          <div>
            <a class="subtle back" routerLink="/listes"><sas-icon name="back" [size]="15" />Listes</a>
            <h1>{{ l.name }}</h1>
            <p class="row meta">
              <sas-eco [ecosystem]="l.ecosystem" [size]="26" />{{ l.ecosystem | ecosystem }}
              <span>·</span>{{ l.artifactCount | plural: 'paquet' }}, {{ l.size | bytes }}
              <span>·</span>{{ l.resolvedAt ? 'mise à jour ' + (l.resolvedAt | relative) : 'jamais mise à jour' }}
            </p>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-ghost btn-danger" (click)="confirmDelete.open()"><sas-icon name="trash" />Supprimer</button>
            <a class="btn" [routerLink]="['/listes', l.id, 'modifier']"><sas-icon name="edit" />Modifier</a>
            <button type="button" class="btn btn-primary" (click)="resolve()" [disabled]="running()">
              <sas-icon name="refresh" />{{ running() ? 'Mise à jour…' : 'Mettre à jour' }}
            </button>
          </div>
        </header>

        @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

        @if (l.job && (running() || l.job.status === 'failed')) {
          <section class="card card-body">
            <sas-job-progress [job]="l.job" />
            @if (l.job.status === 'failed') {
              <a routerLink="/activite" class="subtle">Voir le journal complet</a>
            }
          </section>
        }

        <section class="card">
          <header class="card-header">
            <h2>Contenu</h2>
            <div class="row filters">
              <label class="visually-hidden" for="filter">Filtrer</label>
              <input class="input" id="filter" type="search" placeholder="Filtrer…" [ngModel]="filter()" (ngModelChange)="filter.set($event)" />
              <label class="check"><input type="checkbox" [ngModel]="pendingOnly()" (ngModelChange)="pendingOnly.set($event)" /> À exporter</label>
            </div>
          </header>
          @if (artifacts().length === 0) {
            <div class="empty">
              <sas-icon name="package" [size]="28" />
              <h3>Liste pas encore téléchargée</h3>
              <p>Lancez une mise à jour pour résoudre les dépendances et télécharger les paquets.</p>
            </div>
          } @else {
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr><th>Nom</th><th>Version</th><th>{{ l.ecosystem === 'docker' ? 'Plateformes' : l.ecosystem === 'pypi' ? 'Fichier' : 'Description' }}</th><th class="right">Taille</th><th>Export</th></tr>
                </thead>
                <tbody>
                  @for (a of visible(); track a.id) {
                    <tr>
                      <td><strong class="name">{{ a.name }}</strong></td>
                      <td class="mono">{{ a.version }}</td>
                      <td class="detail">{{ l.ecosystem === 'pypi' ? a.filename : a.detail }}</td>
                      <td class="right num">{{ a.size | bytes }}</td>
                      <td>@if (a.exportSequence) { <span class="badge">n° {{ a.exportSequence }}</span> } @else { <span class="badge badge-accent">À exporter</span> }</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            @if (visible().length < filtered().length) {
              <div class="more"><button type="button" class="btn btn-sm" (click)="limit.set(limit() + 200)">Afficher plus ({{ filtered().length - visible().length }} restants)</button></div>
            }
          }
        </section>
      } @else if (error()) {
        <div class="alert alert-danger">{{ error() }}</div>
      }
    </div>

    <sas-dialog #confirmDelete heading="Supprimer cette liste ?">
      <p>Les paquets déjà téléchargés restent dans Rémus et dans Romulus ; seule la liste est supprimée.</p>
      <div dialog-actions>
        <button type="button" class="btn" (click)="confirmDelete.close()">Annuler</button>
        <button type="button" class="btn btn-primary" (click)="remove()">Supprimer</button>
      </div>
    </sas-dialog>
  `,
  styles: `
    .back { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; margin-bottom: 6px; }
    .meta { flex-wrap: wrap; color: var(--text-2); }
    .filters { flex-wrap: wrap; }
    .filters .input { width: 220px; min-height: 34px; }
    .name { overflow-wrap: anywhere; }
    .detail { color: var(--text-2); font-size: 0.86rem; max-width: 420px; overflow-wrap: anywhere; }
    .more { display: flex; justify-content: center; padding: 14px; border-top: 1px solid var(--border); }
  `,
})
export default class ListDetail implements OnInit {
  private readonly destroyRef = inject(DestroyRef);
  readonly id = input.required<string>();
  private readonly api = inject(CollectorApi);
  private readonly router = inject(Router);
  private readonly toasts = inject(Toasts);
  private readonly dialog = viewChild.required(Dialog);

  protected readonly list = signal<PackageList | null>(null);
  protected readonly artifacts = signal<Artifact[]>([]);
  protected readonly error = signal('');
  protected readonly filter = signal('');
  protected readonly pendingOnly = signal(false);
  protected readonly limit = signal(200);
  protected readonly running = computed(() => isActive(this.list()?.job));
  protected readonly filtered = computed(() => {
    const q = this.filter().trim().toLowerCase();
    return this.artifacts().filter(
      (a) => (!q || a.name.toLowerCase().includes(q) || (a.filename ?? '').toLowerCase().includes(q)) && (!this.pendingOnly() || !a.exportSequence),
    );
  });
  protected readonly visible = computed(() => this.filtered().slice(0, this.limit()));
  private wasRunning = false;

  ngOnInit(): void {
    // Démarré ici : l'identifiant de la route n'est pas encore disponible dans le constructeur
    poll(async () => {
      await this.load();
      return this.running();
    }, this.destroyRef);
  }

  private async load(): Promise<void> {
    try {
      const list = await this.api.list(+this.id());
      this.list.set(list);
      const running = isActive(list.job);
      // Contenu rechargé au premier affichage et à la fin d'une mise à jour
      if (!running && (this.wasRunning || this.artifacts().length === 0)) {
        this.artifacts.set(await this.api.listArtifacts(list.id));
      }
      if (this.wasRunning && !running && list.job?.status === 'succeeded') {
        this.toasts.ok(`« ${list.name} » à jour : ${list.job.step}`);
      }
      this.wasRunning = running;
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async resolve(): Promise<void> {
    try {
      await this.api.resolve(+this.id());
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async remove(): Promise<void> {
    try {
      await this.api.deleteList(+this.id());
      this.dialog().close();
      this.toasts.ok('Liste supprimée.');
      await this.router.navigate(['/listes']);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
