import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { BytesPipe, CopyButton, SecuritySummary, SeverityBadge, Dialog, EcosystemPipe, Icon, JobProgress, PluralPipe, RelativePipe, Toasts, errorMessage, isActive, poll, Eco } from '@sas/shared';
import { Artifact, CollectorApi, PackageList, Template } from '../api';

@Component({
  imports: [Eco, CopyButton, SecuritySummary, SeverityBadge, FormsModule, RouterLink, Icon, JobProgress, Dialog, BytesPipe, RelativePipe, EcosystemPipe, PluralPipe],
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

        @if (l.ecosystem !== 'docker' && l.resolvedAt) {
          @let sec = l.security;
          <section class="card card-body security" [class.critical]="sec.critical" [class.warning]="!sec.critical && sec.warning">
            <sas-icon [name]="sec.critical || sec.warning ? 'alert' : 'shield'" [size]="22" />
            <div class="grow">
              <strong>{{ sec.critical || sec.warning ? 'Alertes de sécurité' : 'Aucune vulnérabilité connue' }}</strong>
              <span class="muted">{{ sec.critical || sec.warning ? 'Cliquez sur la gravité d’un paquet pour le détail et la version corrigée.' : 'D’après OSV.dev à la dernière analyse.' }}</span>
            </div>
            <sas-security-summary [counts]="sec" />
            <button type="button" class="btn btn-sm" (click)="audit()" [disabled]="running()"><sas-icon name="refresh" [size]="15" />Analyser</button>
          </section>
        }

        @if (template(); as t) {
          <section class="card card-body template">
            <sas-eco [ecosystem]="t.ecosystem" [size]="40" />
            <div class="grow">
              <span class="eyebrow">Modèle {{ t.label }} · {{ l.options.template?.version }}</span>
              <p class="muted">{{ t.description }} Après import dans Romulus, sur le réseau isolé :</p>
              <div class="cmd"><pre class="kbd-block">{{ usage() }}</pre><sas-copy [text]="usage()" /></div>
              @if (t.id === 'symfony') {
                <p class="subtle">Recettes Symfony Flex comprises : définissez <code>SYMFONY_ENDPOINT</code> sur le poste (voir Utilisation dans Romulus).</p>
              }
            </div>
          </section>
        }

        <section class="card">
          <header class="card-header">
            <h2>Contenu</h2>
            <div class="row filters">
              <label class="visually-hidden" for="filter">Filtrer</label>
              <input class="input" id="filter" type="search" placeholder="Filtrer…" [ngModel]="filter()" (ngModelChange)="filter.set($event)" />
              <label class="check"><input type="checkbox" [ngModel]="pendingOnly()" (ngModelChange)="pendingOnly.set($event)" /> À exporter</label>
              @if (l.ecosystem !== 'docker') {
                <label class="check"><input type="checkbox" [ngModel]="vulnerableOnly()" (ngModelChange)="vulnerableOnly.set($event)" /> Vulnérables</label>
              }
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
                  <tr><th>Nom</th><th>Version</th><th>{{ l.ecosystem === 'docker' ? 'Plateformes' : l.ecosystem === 'pypi' ? 'Fichier' : 'Description' }}</th><th class="right">Taille</th>@if (l.ecosystem !== 'docker') { <th>Sécurité</th> }<th>Export</th></tr>
                </thead>
                <tbody>
                  @for (a of visible(); track a.id) {
                    <tr>
                      <td><strong class="name">{{ a.name }}</strong></td>
                      <td class="mono">{{ a.version }}</td>
                      <td class="detail">{{ l.ecosystem === 'pypi' ? a.filename : a.detail }}</td>
                      <td class="right num">{{ a.size | bytes }}</td>
                      @if (l.ecosystem !== 'docker') {
                        <td>
                          @if (a.severity) {
                            <button type="button" class="sev-button" (click)="showVulnerabilities(a)" [attr.aria-label]="'Vulnérabilités de ' + a.name">
                              <sas-severity [severity]="a.severity" [count]="a.vulnerabilityCount" />
                            </button>
                          }
                        </td>
                      }
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

    <sas-dialog #vulnDialog [heading]="inspected() ? inspected()!.name + ' ' + inspected()!.version : 'Vulnérabilités'">
      @if (inspected(); as a) {
        <ul class="vulns">
          @for (v of a.vulnerabilities ?? []; track v.id) {
            <li>
              <div class="row"><sas-severity [severity]="v.severity" /><a [href]="v.url" target="_blank" rel="noopener noreferrer" class="mono">{{ v.id }}</a>
                @if (v.aliases.length) { <span class="subtle mono">{{ v.aliases.join(', ') }}</span> }</div>
              <p>{{ v.summary }}</p>
              <p class="subtle">Corrigée en : <span class="mono">{{ v.fixed.length ? v.fixed.join(', ') : 'aucune version corrigée publiée' }}</span></p>
            </li>
          }
        </ul>
      }
    </sas-dialog>

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
    .security { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .security > sas-icon { color: var(--ok); }
    .security.warning > sas-icon { color: var(--warn); }
    .security.critical > sas-icon { color: var(--danger); }
    .security .grow { flex: 1; display: grid; gap: 2px; min-width: 220px; }
    .sev-button { border: none; background: none; padding: 0; cursor: pointer; }
    .vulns { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; max-height: 60vh; overflow: auto; }
    .vulns li { display: grid; gap: 4px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
    .vulns li:last-child { border-bottom: none; }
    .vulns .row { flex-wrap: wrap; }
    .filters .input { width: 220px; min-height: 34px; }
    .name { overflow-wrap: anywhere; }
    .detail { color: var(--text-2); font-size: 0.86rem; max-width: 420px; overflow-wrap: anywhere; }
    .template { display: flex; gap: 16px; align-items: flex-start; }
    .template .eyebrow { margin-bottom: 4px; }
    .template p { margin-bottom: 10px; }
    .grow { flex: 1; min-width: 0; display: grid; }
    .cmd { display: flex; gap: 8px; align-items: flex-start; }
    .cmd pre { flex: 1; }
    .more { display: flex; justify-content: center; padding: 14px; border-top: 1px solid var(--border); }
  `,
})
export default class ListDetail implements OnInit {
  private readonly destroyRef = inject(DestroyRef);
  readonly id = input.required<string>();
  private readonly api = inject(CollectorApi);
  private readonly router = inject(Router);
  private readonly toasts = inject(Toasts);
  private readonly dialog = viewChild.required<Dialog>('confirmDelete');

  protected readonly list = signal<PackageList | null>(null);
  protected readonly artifacts = signal<Artifact[]>([]);
  protected readonly error = signal('');
  protected readonly filter = signal('');
  protected readonly pendingOnly = signal(false);
  protected readonly vulnerableOnly = signal(false);
  protected readonly inspected = signal<Artifact | null>(null);
  private readonly vulnDialog = viewChild.required<Dialog>('vulnDialog');
  protected readonly limit = signal(200);
  protected readonly templates = signal<Template[]>([]);
  protected readonly template = computed(() => this.templates().find((t) => t.id === this.list()?.options.template?.id) ?? null);
  protected readonly usage = computed(() => (this.template()?.usage ?? '').replaceAll('{version}', this.list()?.options.template?.version ?? 'latest'));
  protected readonly running = computed(() => isActive(this.list()?.job));
  protected readonly filtered = computed(() => {
    const q = this.filter().trim().toLowerCase();
    return this.artifacts().filter(
      (a) => (!q || a.name.toLowerCase().includes(q) || (a.filename ?? '').toLowerCase().includes(q)) && (!this.pendingOnly() || !a.exportSequence) && (!this.vulnerableOnly() || !!a.severity),
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
      if (!this.templates().length) this.templates.set(await this.api.templates());
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

  protected showVulnerabilities(artifact: Artifact): void {
    this.inspected.set(artifact);
    this.vulnDialog().open();
  }

  protected async audit(): Promise<void> {
    try {
      await this.api.audit(+this.id());
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
