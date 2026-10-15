import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { ECOSYSTEMS, EcosystemId, Icon, Toasts, errorMessage, formatBytes, Eco } from '@sas/shared';
import { CollectorApi, EcosystemChoices, ListInput, ListTemplate, PypiTarget, Template } from '../api';
import { PackagePicker } from '../package-picker';
import { addLines, containsAll, duplicates, lineKey, removeDuplicates, removeLines, specLines } from '../spec-lines';

const HELP: Record<EcosystemId, { placeholder: string; hint: string; lockfile: string; accept: string }> = {
  npm: {
    placeholder: 'express@^4\nlodash\n@angular/core@~22.2.0\n# commentaire',
    hint: 'Une ligne par paquet : nom@version (plage semver acceptée). Les dépendances sont ajoutées automatiquement.',
    lockfile: 'package-lock.json',
    accept: '.json',
  },
  pypi: {
    placeholder: 'requests==2.32.3\nnumpy>=2\ndjango>=5,<6',
    hint: 'Une exigence par ligne, comme dans un requirements.txt. Les dépendances sont résolues pour chaque cible.',
    lockfile: 'requirements.txt',
    accept: '.txt,.in',
  },
  composer: {
    placeholder: 'monolog/monolog:^3.0\nsymfony/console ^7.3\nguzzlehttp/guzzle',
    hint: 'Une ligne par paquet : vendeur/paquet:contrainte. Les dépendances sont ajoutées automatiquement.',
    lockfile: 'composer.lock ou composer.json',
    accept: '.json,.lock',
  },
  docker: {
    placeholder: 'nginx:1.27\npostgres:17-alpine\nghcr.io/owner/app:1.0',
    hint: 'Une image par ligne. Sans registre, Docker Hub est utilisé ; sans étiquette, « latest ».',
    lockfile: '',
    accept: '',
  },
};

@Component({
  imports: [Eco, PackagePicker, FormsModule, RouterLink, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form class="page" (ngSubmit)="save(false)">
      <header class="page-header">
        <div>
          <a class="subtle back" [routerLink]="id() ? ['/listes', id()] : '/listes'"><sas-icon name="back" [size]="15" />Retour</a>
          <h1>{{ id() ? 'Modifier la liste' : 'Nouvelle liste' }}</h1>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger" role="alert">{{ error() }}</div> }

      @if (!id()) {
        <fieldset class="card card-body ecosystems">
          <legend class="label">Écosystème</legend>
          @for (eco of ecosystems; track eco.id) {
            <label class="eco-choice" [class.selected]="ecosystem() === eco.id">
              <input type="radio" name="ecosystem" [value]="eco.id" [ngModel]="ecosystem()" (ngModelChange)="setEcosystem($event)" />
              <sas-eco [ecosystem]="eco.id" [size]="22" />
              <strong>{{ eco.label }}</strong>
            </label>
          }
        </fieldset>
      }

      @if (ecosystemTemplates().length) {
        <section class="card card-body stack">
          <div>
            <h2>{{ hasPresets() ? 'Listes prêtes à l’emploi' : 'Partir d’un modèle' }}</h2>
            <p class="subtle">
              @if (hasPresets()) {
                Cochez une ou plusieurs listes : leurs paquets sont ajoutés sans doublon. Un second clic retire la liste.
              } @else {
                Pour qu’une commande comme « ng new » ou « composer create-project » fonctionne hors ligne, Rémus exécute le générateur et collecte tout ce qu’il installe.
              }
            </p>
          </div>
          <div class="templates">
            @for (t of ecosystemTemplates(); track t.id) {
              @let on = isSelected(t);
              <button type="button" class="template" [class.selected]="on" [attr.aria-pressed]="on" (click)="applyTemplate(t)">
                <span class="row">
                  <sas-eco [ecosystem]="t.ecosystem" [size]="26" />
                  <strong class="grow">{{ t.label }}</strong>
                  @if (t.kind === 'generator') { <span class="badge badge-accent">Générateur</span> }
                  <span class="tick" [class.radio]="t.kind === 'generator'" aria-hidden="true">@if (on) { <sas-icon name="check" [size]="14" /> }</span>
                </span>
                <span class="subtle">{{ t.description }}</span>
                @if (t.kind === 'preset') { <span class="count">{{ presetLines(t).length }} {{ t.ecosystem === 'docker' ? 'images' : 'paquets' }}</span> }
              </button>
            }
          </div>
        </section>
      }

      <section class="card card-body stack">
        @if (selectedTemplate(); as t) {
          <div class="chosen">
            <div class="row head">
              <sas-eco [ecosystem]="t.ecosystem" [size]="34" />
              <div class="grow">
                <strong>Modèle {{ t.label }}</strong>
                <div class="subtle">{{ t.description }}</div>
              </div>
              <button type="button" class="btn btn-sm btn-ghost" (click)="template.set(null)"><sas-icon name="x" [size]="15" />Retirer</button>
            </div>
            <div class="field version">
              <label for="tpl-version">Version</label>
              <input class="input" id="tpl-version" name="tplVersion" [ngModel]="template()?.version" (ngModelChange)="setTemplateVersion($event)" />
              <span class="hint">{{ t.versionHint }}</span>
            </div>
            @if (t.usage) {
              <div class="field">
                <span class="label">Ensuite, sur le réseau isolé</span>
                <pre class="kbd-block">{{ usage(t) }}</pre>
              </div>
            }
          </div>
        }

        <div class="field">
          <label for="name">Nom de la liste</label>
          <input class="input" id="name" name="name" required maxlength="120" [ngModel]="name" (ngModelChange)="name = $event; nameEdited = true" placeholder="ex. Projet web, Outils data…" />
        </div>

        <div class="field">
          <span class="label">Ajouter depuis le registre</span>
          <app-package-picker [ecosystem]="ecosystem()" (add)="addFromPicker($event)" />
        </div>

        <div class="field">
          <label for="specs">{{ ecosystem() === 'docker' ? 'Images' : selectedTemplate() ? 'Paquets supplémentaires' : 'Paquets' }}@if (selectedTemplate()) { <span class="subtle">(facultatif)</span> }</label>
          <textarea class="textarea" id="specs" name="specs" rows="9" [ngModel]="specs()" (ngModelChange)="specs.set($event)" [placeholder]="help().placeholder"
            spellcheck="false" autocapitalize="off"></textarea>
          <span class="hint">{{ help().hint }}</span>
          @if (duplicateKeys().length) {
            <div class="alert alert-warn duplicates" role="status">
              <sas-icon name="alert" />
              <span class="grow">En double : {{ duplicateKeys().join(', ') }}</span>
              <button type="button" class="btn btn-sm" (click)="dedupe()">Retirer les doublons</button>
            </div>
          }
        </div>

        @if (help().lockfile) {
          <div class="field">
            <span class="label">Fichier de verrouillage <span class="subtle">(facultatif)</span></span>
            @if (lockfileName() && !removeLockfile()) {
              <div class="row file">
                <sas-icon name="file" />
                <span class="mono">{{ lockfileName() }}</span>
                @if (lockfileSize()) { <span class="subtle">{{ lockfileSize() }}</span> }
                <button type="button" class="btn btn-sm btn-ghost" (click)="clearLockfile()"><sas-icon name="x" [size]="15" />Retirer</button>
              </div>
            } @else {
              <label class="drop" [class.over]="dragOver()" (dragover)="$event.preventDefault(); dragOver.set(true)"
                (dragleave)="dragOver.set(false)" (drop)="drop($event)">
                <input type="file" class="visually-hidden" [accept]="help().accept" (change)="pick($event)" />
                <sas-icon name="upload" />
                <span>Déposez un <strong>{{ help().lockfile }}</strong> ou <u>parcourez</u></span>
              </label>
            }
            <span class="hint">Les versions exactes du fichier sont collectées, en plus des paquets saisis ci-dessus.</span>
          </div>
        }
      </section>

      <section class="card card-body stack">
        <h2>Options</h2>
        @switch (ecosystem()) {
          @case ('npm') {
            <label class="check"><input type="checkbox" name="includeDev" [(ngModel)]="includeDev" />
              <span>Inclure les dépendances de développement du fichier de verrouillage</span></label>
            <label class="check"><input type="checkbox" name="legacyPeerDeps" [(ngModel)]="legacyPeerDeps" />
              <span>Ignorer les conflits de dépendances « peer » (--legacy-peer-deps)<br /><span class="hint">Appliqué automatiquement si npm signale un conflit.</span></span></label>
          }
          @case ('pypi') {
            <div class="field">
              <span class="label">Cibles</span>
              <span class="hint">Les wheels sont spécifiques à une version de Python et à un système : ajoutez chaque combinaison utilisée sur le réseau isolé.</span>
              <div class="targets">
                @for (target of targets(); track $index; let i = $index) {
                  <div class="target">
                    <select class="select" [name]="'python' + i" [(ngModel)]="target.python" aria-label="Version de Python">
                      @for (v of choices()?.pypi?.pythonVersions ?? []; track v) { <option [value]="v">Python {{ v }}</option> }
                    </select>
                    <select class="select" [name]="'platform' + i" [(ngModel)]="target.platform" aria-label="Plateforme">
                      @for (p of choices()?.pypi?.platforms ?? []; track p.id) { <option [value]="p.id">{{ p.label }}</option> }
                    </select>
                    <button type="button" class="btn btn-icon btn-ghost" (click)="removeTarget(i)" [disabled]="targets().length === 1" aria-label="Retirer cette cible">
                      <sas-icon name="trash" [size]="16" />
                    </button>
                  </div>
                }
              </div>
              <button type="button" class="btn btn-sm add" (click)="addTarget()"><sas-icon name="plus" [size]="15" />Ajouter une cible</button>
            </div>
            <label class="check"><input type="checkbox" name="sdist" [(ngModel)]="sdist" />
              <span>Ajouter aussi les sources (sdist) des versions retenues<br /><span class="hint">Utile pour compiler sur une plateforme non prévue.</span></span></label>
          }
          @case ('composer') {
            <div class="field php">
              <label for="php">Version de PHP cible <span class="subtle">(facultatif)</span></label>
              <input class="input" id="php" name="php" [(ngModel)]="php" placeholder="ex. 8.3" inputmode="decimal" />
              <span class="hint">Choisit des versions compatibles avec ce PHP. Vide : contraintes de plateforme ignorées.</span>
            </div>
            <label class="check"><input type="checkbox" name="includeDevComposer" [(ngModel)]="includeDev" />
              <span>Inclure require-dev (composer.json / composer.lock importé)</span></label>
          }
          @case ('docker') {
            <fieldset class="field">
              <legend class="label">Plateformes</legend>
              <div class="chips">
                @for (p of choices()?.docker?.platforms ?? []; track p.id) {
                  <label class="chip"><input type="checkbox" [name]="'pf-' + p.id" [checked]="platforms().includes(p.id)" (change)="togglePlatform(p.id)" />{{ p.label }}</label>
                }
              </div>
              <span class="hint">Seules ces plateformes sont téléchargées ; l’image reste utilisable par son nom et son empreinte d’origine.</span>
            </fieldset>
          }
        }
      </section>

      <div class="actions">
        <button type="submit" class="btn" [disabled]="busy()">Enregistrer</button>
        <button type="button" class="btn btn-primary" (click)="save(true)" [disabled]="busy()"><sas-icon name="download" />Enregistrer et télécharger</button>
      </div>
    </form>
  `,
  styles: `
    .back { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; margin-bottom: 6px; }
    .ecosystems { border: none; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0; }
    .ecosystems legend { padding: 0 4px; }
    .eco-choice { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-radius: 14px; background: var(--surface-2); box-shadow: inset 0 0 0 1px var(--border); cursor: pointer; transition-property: background-color, box-shadow; transition-duration: 140ms; }
    .eco-choice.selected { background: var(--accent-soft); box-shadow: inset 0 0 0 1.5px var(--accent); }
    .eco-choice input { margin: 0; }
    .drop { display: flex; align-items: center; gap: 10px; padding: 18px; border: 1.5px dashed var(--border-strong); border-radius: 14px; background: var(--surface-2);
      color: var(--text-2); cursor: pointer; transition-property: background-color, box-shadow; transition-duration: 140ms; }
    .drop:hover, .drop.over { border-color: var(--accent); background: var(--accent-soft); }
    .drop:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
    .file { padding: 10px 12px; background: var(--surface-2); border-radius: var(--radius-s); flex-wrap: wrap; }
    .targets { display: grid; gap: 8px; }
    .target { display: grid; grid-template-columns: 150px 1fr auto; gap: 8px; }
    .add { justify-self: start; }
    .templates { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
    .template { display: grid; gap: 6px; padding: 14px 16px; border: none; border-radius: 14px; text-align: left; font: inherit; color: inherit;
      cursor: pointer; background: var(--surface-2); box-shadow: inset 0 0 0 1px var(--border);
      transition-property: background-color, box-shadow; transition-duration: 140ms; }
    .template:hover { box-shadow: inset 0 0 0 1px var(--border-strong); }
    .template.selected { background: var(--accent-soft); box-shadow: inset 0 0 0 1.5px var(--accent); }
    .template .subtle { font-size: 0.82rem; }
    .template .tick { display: grid; place-items: center; width: 20px; height: 20px; border-radius: 6px; flex: none;
      box-shadow: inset 0 0 0 1.5px var(--border-strong); color: var(--on-accent, #fff); }
    .template.selected .tick { background: var(--accent); box-shadow: none; }
    /* Générateurs : un seul à la fois (case ronde) ; listes prédéfinies : cumulables (case carrée) */
    .template .tick.radio { border-radius: 50%; }
    .template .count { font-size: 0.78rem; font-weight: 650; color: var(--text-3); }
    .template.selected .count { color: var(--accent-strong); }
    .duplicates { align-items: center; padding: 10px 14px; }
    .chosen { display: grid; gap: 14px; padding: 16px; border-radius: 14px; background: var(--accent-soft); }
    .chosen .head { align-items: flex-start; }
    .grow { flex: 1; }
    .version { max-width: 260px; }
    .php { max-width: 340px; }
    fieldset.field { border: none; padding: 0; margin: 0; }
    @media (max-width: 600px) { .target { grid-template-columns: 1fr auto; } .target select:first-child { grid-column: 1 / -1; } }
  `,
})
export default class ListForm implements OnInit {
  readonly id = input<string>();
  private readonly api = inject(CollectorApi);
  private readonly router = inject(Router);
  private readonly toasts = inject(Toasts);

  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly choices = signal<EcosystemChoices | null>(null);
  protected readonly ecosystem = signal<EcosystemId>('npm');
  protected readonly help = computed(() => HELP[this.ecosystem()]);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly dragOver = signal(false);

  protected name = '';
  /** Nom saisi par l'utilisateur : on ne le remplace plus automatiquement */
  protected nameEdited = false;
  protected readonly specs = signal('');
  protected includeDev = true;
  protected legacyPeerDeps = false;
  protected sdist = false;
  protected php = '';
  protected readonly targets = signal<PypiTarget[]>([{ python: '3.12', platform: 'linux-x86_64' }]);
  protected readonly platforms = signal<string[]>(['linux/amd64']);
  protected readonly templates = signal<Template[]>([]);
  protected readonly template = signal<ListTemplate | null>(null);
  protected readonly ecosystemTemplates = computed(() => this.templates().filter((t) => t.ecosystem === this.ecosystem()));
  protected readonly selectedTemplate = computed(() => this.templates().find((t) => t.id === this.template()?.id) ?? null);
  protected readonly hasPresets = computed(() => this.ecosystemTemplates().some((t) => t.kind === 'preset'));
  protected readonly duplicateKeys = computed(() => duplicates(this.specs(), this.ecosystem()));

  protected readonly lockfileName = signal<string | null>(null);
  protected readonly lockfileSize = signal('');
  protected readonly removeLockfile = signal(false);
  private lockfileContent: string | null = null;

  async ngOnInit(): Promise<void> {
    try {
      this.choices.set(await this.api.ecosystems());
      this.templates.set(await this.api.templates());
      const id = this.id();
      if (!id) {
        return;
      }
      const list = await this.api.list(+id);
      this.ecosystem.set(list.ecosystem);
      this.name = list.name;
      this.nameEdited = true;
      this.specs.set(list.specs ?? '');
      this.lockfileName.set(list.lockfileName);
      const o = list.options;
      this.includeDev = o.includeDev ?? list.ecosystem === 'npm';
      this.legacyPeerDeps = o.legacyPeerDeps ?? false;
      this.sdist = o.sdist ?? false;
      this.php = o.php ?? '';
      if (o.targets?.length) this.targets.set(o.targets.map((t) => ({ ...t })));
      if (o.platforms?.length) this.platforms.set([...o.platforms]);
      this.template.set(o.template ?? null);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected setEcosystem(id: EcosystemId): void {
    this.ecosystem.set(id);
    if (this.selectedTemplate()?.ecosystem !== id) this.template.set(null);
    this.autoName();
  }

  protected presetLines(t: Template): string[] {
    return specLines(t.specs ?? '');
  }

  /** Générateur : celui retenu ; liste prédéfinie : tous ses paquets sont dans le champ (suit la saisie). */
  protected isSelected(t: Template): boolean {
    return t.kind === 'generator' ? this.template()?.id === t.id : containsAll(this.specs(), this.presetLines(t), this.ecosystem());
  }

  /**
   * Générateur : retenu avec sa version (un seul à la fois).
   * Liste prédéfinie : ajoutée sans doublon, ou retirée si elle est déjà cochée ; les paquets
   * qu'elle partage avec une autre liste encore cochée sont conservés.
   */
  protected applyTemplate(t: Template): void {
    const eco = this.ecosystem();
    if (t.kind === 'generator') {
      this.template.set({ id: t.id, version: t.version ?? 'latest' });
    } else if (this.isSelected(t)) {
      const keep = new Set(
        this.ecosystemTemplates()
          .filter((o) => o.kind === 'preset' && o.id !== t.id && this.isSelected(o))
          .flatMap((o) => this.presetLines(o).map((line) => lineKey(line, eco))),
      );
      this.specs.set(removeLines(this.specs(), this.presetLines(t), eco, keep));
    } else {
      this.specs.set(addLines(this.specs(), this.presetLines(t), eco));
    }
    this.autoName();
  }

  /** Nom proposé d'après les modèles cochés, tant que l'utilisateur n'a pas saisi le sien. */
  private autoName(): void {
    if (this.nameEdited) return;
    const chosen = this.ecosystemTemplates().filter((t) => this.isSelected(t));
    this.name = chosen
      .map((t) => (t.kind === 'generator' && this.template()?.version !== 'latest' ? `${t.label} ${this.template()?.version}` : t.label))
      .join(' + ');
  }

  /** Lignes choisies dans la recherche : ajoutées sans doublon */
  protected addFromPicker(lines: string[]): void {
    const before = specLines(this.specs()).length;
    this.specs.set(addLines(this.specs(), lines, this.ecosystem()));
    const added = specLines(this.specs()).length - before;
    if (added) {
      this.toasts.ok(added > 1 ? `${added} lignes ajoutées.` : 'Ajouté à la liste.');
    } else {
      this.toasts.info('Déjà dans la liste.');
    }
  }

  protected dedupe(): void {
    this.specs.set(removeDuplicates(this.specs(), this.ecosystem()));
  }

  protected setTemplateVersion(version: string): void {
    const current = this.template();
    if (current) this.template.set({ ...current, version: version.trim() });
  }

  protected usage(t: Template): string {
    return (t.usage ?? '').replaceAll('{version}', this.template()?.version || 'latest');
  }

  protected addTarget(): void {
    const last = this.targets().at(-1);
    this.targets.update((t) => [...t, { python: last?.python ?? '3.12', platform: 'windows-amd64' }]);
  }

  protected removeTarget(index: number): void {
    this.targets.update((t) => t.filter((_, i) => i !== index));
  }

  protected togglePlatform(id: string): void {
    this.platforms.update((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
  }

  protected pick(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) void this.read(file);
  }

  protected drop(event: DragEvent): void {
    event.preventDefault();
    this.dragOver.set(false);
    const file = event.dataTransfer?.files[0];
    if (file) void this.read(file);
  }

  protected clearLockfile(): void {
    this.lockfileContent = null;
    this.lockfileName.set(null);
    this.lockfileSize.set('');
    this.removeLockfile.set(!!this.id());
  }

  private async read(file: File): Promise<void> {
    if (file.size > 10 * 2 ** 20) {
      this.error.set('Fichier trop volumineux (10 Mo maximum).');
      return;
    }
    this.lockfileContent = await file.text();
    this.lockfileName.set(file.name);
    this.lockfileSize.set(formatBytes(file.size));
    this.removeLockfile.set(false);
  }

  protected async save(resolve: boolean): Promise<void> {
    if (this.ecosystem() === 'docker' && this.platforms().length === 0) {
      this.error.set('Choisissez au moins une plateforme.');
      return;
    }
    const input: ListInput = {
      name: this.name.trim(),
      ecosystem: this.ecosystem(),
      specs: this.specs(),
      options: {
        includeDev: this.includeDev,
        legacyPeerDeps: this.legacyPeerDeps,
        targets: this.targets(),
        sdist: this.sdist,
        php: this.php.trim() || null,
        platforms: this.platforms(),
        template: this.template(),
      },
      lockfileName: this.lockfileContent ? this.lockfileName() : null,
      lockfileContent: this.lockfileContent,
      removeLockfile: this.removeLockfile(),
    };

    this.busy.set(true);
    this.error.set('');
    try {
      const id = this.id();
      const list = id ? await this.api.updateList(+id, input) : await this.api.createList(input);
      if (resolve) {
        await this.api.resolve(list.id);
        this.toasts.ok('Liste enregistrée, téléchargement lancé.');
      } else {
        this.toasts.ok('Liste enregistrée.');
      }
      await this.router.navigate(['/listes', list.id]);
    } catch (e) {
      this.error.set(errorMessage(e));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      this.busy.set(false);
    }
  }
}
