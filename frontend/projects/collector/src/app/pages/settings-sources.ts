import { ChangeDetectionStrategy, Component, OnInit, inject, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Dialog, ECOSYSTEMS, Eco, EcosystemId, EcosystemPipe, Icon, Toasts, errorMessage } from '@sas/shared';
import { CollectorApi, Source, SourceInput } from '../api';

const HINTS: Partial<Record<EcosystemId, { url: string; help: string }>> = {
  npm: { url: 'https://npm.example.com', help: 'Verdaccio, Nexus, Artifactory, GitLab… Une portée (@entreprise) envoie ces paquets vers ce dépôt ; sans portée, il remplace npmjs.' },
  pypi: { url: 'https://pypi.example.com/simple', help: 'Adresse de l’index « simple » (devpi, Nexus, Artifactory, GitLab). Ajouté à PyPI (--extra-index-url) ou à sa place.' },
  composer: { url: 'https://repo.example.com', help: 'Dépôt de type « composer » : Satis, Private Packagist, Nexus, GitLab. Consulté avant Packagist, ou à sa place.' },
  docker: { url: 'harbor.example.com', help: 'Hôte du registre (Harbor, GitLab, Nexus, registry:2). Les images « harbor.example.com/projet/app:1.0 » l’utilisent ; s’il remplace Docker Hub, les images officielles y sont tirées.' },
};

/** Réglages › Dépôts sources : dépôts privés en plus des dépôts officiels. */
@Component({
  selector: 'sas-settings-sources',
  imports: [FormsModule, Icon, Eco, EcosystemPipe, Dialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card" id="depots">
      <header class="card-header">
        <div>
          <h2>Dépôts sources</h2>
          <p class="subtle">Les dépôts officiels (npmjs, PyPI, Packagist, Docker Hub) sont toujours utilisés, sauf si un dépôt privé les remplace.</p>
        </div>
        <button type="button" class="btn btn-primary" (click)="edit()"><sas-icon name="plus" />Ajouter un dépôt</button>
      </header>
      @if (error()) { <div class="card-body"><div class="alert alert-danger">{{ error() }}</div></div> }
      @if (items().length === 0) {
        <div class="empty">
          <sas-icon name="globe" [size]="28" />
          <h3>Aucun dépôt privé</h3>
          <p>Rémus télécharge depuis les dépôts officiels.</p>
        </div>
      } @else {
        <ul class="sources">
          @for (s of items(); track s.id) {
            <li [class.off]="!s.enabled">
              <sas-eco [ecosystem]="s.ecosystem" [size]="28" />
              <span class="what">
                <strong>{{ s.label }}</strong>
                @if (s.scope) { <span class="badge badge-accent mono">{{ s.scope }}</span> }
                @if (s.replacesDefault) { <span class="badge badge-warn">remplace le dépôt officiel</span> }
                @if (!s.enabled) { <span class="badge">désactivé</span> }
                <br><span class="subtle mono">{{ s.url }}</span>
                <span class="subtle"> · {{ s.ecosystem | ecosystem }} · {{ authLabel(s) }}</span>
              </span>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" (click)="edit(s)" [attr.aria-label]="'Modifier ' + s.label"><sas-icon name="edit" /></button>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" (click)="remove(s)" [attr.aria-label]="'Supprimer ' + s.label"><sas-icon name="trash" /></button>
            </li>
          }
        </ul>
      }
    </section>

    <sas-dialog #dialog [heading]="form.id ? 'Modifier le dépôt' : 'Nouveau dépôt source'">
      <form id="source-form" class="stack" (ngSubmit)="save()">
        @if (formError()) { <div class="alert alert-danger">{{ formError() }}</div> }
        <div class="grid two">
          <div class="field">
            <label for="src-eco">Écosystème</label>
            <select class="select" id="src-eco" name="ecosystem" [(ngModel)]="form.ecosystem" [disabled]="!!form.id" (ngModelChange)="tested.set(null)">
              @for (e of ecosystems; track e.id) { <option [value]="e.id">{{ e.label }}</option> }
            </select>
          </div>
          <div class="field">
            <label for="src-label">Nom</label>
            <input class="input" id="src-label" name="label" required maxlength="120" [(ngModel)]="form.label" placeholder="Nexus de l’entreprise" />
          </div>
        </div>
        <div class="field">
          <label for="src-url">Adresse</label>
          <input class="input mono" id="src-url" name="url" required [(ngModel)]="form.url" [placeholder]="hint().url" (ngModelChange)="tested.set(null)" />
          <span class="hint">{{ hint().help }}</span>
        </div>
        @if (form.ecosystem === 'npm') {
          <div class="field">
            <label for="src-scope">Portée</label>
            <input class="input mono" id="src-scope" name="scope" [(ngModel)]="form.scope" placeholder="@entreprise" [disabled]="form.replacesDefault" />
          </div>
        }
        <label class="check"><input type="checkbox" name="replaces" [(ngModel)]="form.replacesDefault" />
          <span>Remplace le dépôt officiel<br><span class="subtle">Pour un miroir d’entreprise qui relaie aussi les paquets publics (accès Internet filtré).</span></span>
        </label>

        <div class="grid two">
          <div class="field">
            <label for="src-auth">Authentification</label>
            <select class="select" id="src-auth" name="authType" [(ngModel)]="form.authType" (ngModelChange)="tested.set(null)">
              <option value="none">Aucune</option>
              <option value="basic">Identifiant et mot de passe</option>
              <option value="token">Jeton d’accès</option>
            </select>
          </div>
          @if (form.authType !== 'none' && (form.authType === 'basic' || form.ecosystem === 'docker')) {
            <div class="field">
              <label for="src-user">Identifiant</label>
              <input class="input" id="src-user" name="username" autocomplete="off" [(ngModel)]="form.username" />
            </div>
          }
        </div>
        @if (form.authType !== 'none') {
          <div class="field">
            <label for="src-secret">{{ form.authType === 'token' ? 'Jeton' : 'Mot de passe' }}</label>
            <input class="input mono" id="src-secret" name="secret" type="password" autocomplete="new-password" [(ngModel)]="form.secret"
              [placeholder]="hasSecret() ? 'Inchangé (enregistré, chiffré)' : ''" (ngModelChange)="tested.set(null)" />
            <span class="hint">Chiffré dans la base avec la clé du collecteur ; jamais réaffiché.</span>
          </div>
        }
        <label class="check"><input type="checkbox" name="enabled" [(ngModel)]="form.enabled" /><span>Actif</span></label>
        @if (tested(); as t) { <div class="alert" [class.alert-ok]="t.ok" [class.alert-danger]="!t.ok">{{ t.message }}</div> }
      </form>
      <ng-container dialog-actions>
        <button type="button" class="btn btn-ghost" (click)="test()" [disabled]="testing() || !form.url.trim()"><sas-icon name="refresh" />{{ testing() ? 'Test…' : 'Tester' }}</button>
        <button type="button" class="btn btn-ghost" (click)="dialog.close()">Annuler</button>
        <button type="submit" form="source-form" class="btn btn-primary" [disabled]="busy()">Enregistrer</button>
      </ng-container>
    </sas-dialog>
  `,
  styles: `
    .card-header p { margin: 4px 0 0; }
    .sources { list-style: none; margin: 0; padding: 0; }
    .sources li { display: flex; align-items: center; gap: 12px; padding: 14px 24px; border-top: 1px solid var(--border); }
    .sources li.off { opacity: 0.6; }
    .what { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .what .badge { margin-left: 6px; }
    .two { grid-template-columns: 1fr 1fr; }
    @media (max-width: 600px) { .two { grid-template-columns: 1fr; } }
  `,
})
export class SettingsSources implements OnInit {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  private readonly dialog = viewChild.required<Dialog>('dialog');
  /** Dépôts privés : npm, Python, Composer, Docker (pas encore Debian ni RPM) */
  protected readonly ecosystems = ECOSYSTEMS.filter((e) => e.id in HINTS);
  protected readonly items = signal<Source[]>([]);
  protected readonly error = signal('');
  protected readonly formError = signal('');
  protected readonly busy = signal(false);
  protected readonly testing = signal(false);
  protected readonly tested = signal<{ ok: boolean; message: string } | null>(null);
  protected readonly hasSecret = signal(false);
  protected form: SourceInput = SettingsSources.blank();

  private static blank(): SourceInput {
    return { ecosystem: 'npm', label: '', url: '', scope: null, authType: 'none', username: null, secret: null, replacesDefault: false, enabled: true };
  }

  ngOnInit(): void {
    void this.load();
  }

  protected hint(): { url: string; help: string } {
    return HINTS[this.form.ecosystem] ?? { url: '', help: '' };
  }

  protected authLabel(s: Source): string {
    return { none: 'sans authentification', basic: `identifiant ${s.username ?? ''}`, token: 'jeton' }[s.authType];
  }

  protected edit(source?: Source): void {
    this.form = source
      ? { id: source.id, ecosystem: source.ecosystem, label: source.label, url: source.url, scope: source.scope, authType: source.authType, username: source.username, secret: null, replacesDefault: source.replacesDefault, enabled: source.enabled }
      : SettingsSources.blank();
    this.hasSecret.set(!!source?.hasSecret);
    this.formError.set('');
    this.tested.set(null);
    this.dialog().open();
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.saveSource(this.payload());
      this.toasts.ok('Dépôt enregistré.');
      this.dialog().close();
      await this.load();
    } catch (e) {
      this.formError.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected async test(): Promise<void> {
    this.testing.set(true);
    try {
      this.tested.set(await this.api.testSource(this.payload()));
    } catch (e) {
      this.tested.set({ ok: false, message: errorMessage(e) });
    } finally {
      this.testing.set(false);
    }
  }

  protected async remove(source: Source): Promise<void> {
    if (!confirm(`Supprimer le dépôt « ${source.label} » ?`)) return;
    try {
      await this.api.deleteSource(source.id);
      this.toasts.ok('Dépôt supprimé.');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private payload(): SourceInput {
    const f = this.form;
    return { ...f, scope: f.ecosystem === 'npm' && !f.replacesDefault ? f.scope?.trim() || null : null, secret: f.secret?.trim() || null, username: f.username?.trim() || null };
  }

  private async load(): Promise<void> {
    try {
      this.items.set(await this.api.sources());
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
