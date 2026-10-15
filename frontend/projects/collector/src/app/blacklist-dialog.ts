import { ChangeDetectionStrategy, Component, inject, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Dialog, ECOSYSTEMS, EcosystemId, Toasts, errorMessage } from '@sas/shared';
import { BlacklistEntry, BlacklistInput, CollectorApi } from './api';

/** Ajout d'une version précise à la liste noire (depuis les réglages, la sécurité ou une liste). */
@Component({
  selector: 'sas-blacklist-dialog',
  imports: [FormsModule, Dialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <sas-dialog #dialog heading="Ajouter à la liste noire">
      <form id="blacklist-form" class="stack" (ngSubmit)="submit()">
        <p class="muted">Cette version ne sera plus téléchargée ni exportée par Rémus. Au prochain import, Romulus la met en quarantaine : ses registres cessent de la servir.</p>
        @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }
        <div class="grid two">
          <div class="field">
            <label for="bl-eco">Écosystème</label>
            <select class="select" id="bl-eco" name="ecosystem" [(ngModel)]="input.ecosystem">
              @for (e of ecosystems; track e.id) { <option [value]="e.id">{{ e.label }}</option> }
            </select>
          </div>
          <div class="field">
            <label for="bl-version">Version précise</label>
            <input class="input mono" id="bl-version" name="version" required [(ngModel)]="input.version" [placeholder]="input.ecosystem === 'docker' ? '1.27.3' : '4.17.20'" />
          </div>
        </div>
        <div class="field">
          <label for="bl-name">{{ input.ecosystem === 'docker' ? 'Image' : 'Paquet' }}</label>
          <input class="input mono" id="bl-name" name="name" required [(ngModel)]="input.name" [placeholder]="placeholder()" />
        </div>
        <div class="field">
          <label for="bl-reason">Motif <span class="subtle">(affiché dans Romulus)</span></label>
          <input class="input" id="bl-reason" name="reason" maxlength="500" [(ngModel)]="input.reason" placeholder="CVE-2021-23337, licence non validée…" />
        </div>
        <span class="hint">Pas d’intervalle ni de motif : une entrée vise une seule version (4.17.20, pas ^4.17).</span>
      </form>
      <ng-container dialog-actions>
        <button type="button" class="btn btn-ghost" (click)="dialog.close()">Annuler</button>
        <button type="submit" form="blacklist-form" class="btn btn-danger" [disabled]="busy() || !input.name.trim() || !input.version.trim()">Interdire cette version</button>
      </ng-container>
    </sas-dialog>
  `,
  styles: `.two { grid-template-columns: 1fr 1fr; }`,
})
export class BlacklistDialog {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  readonly added = output<BlacklistEntry>();
  private readonly dialog = viewChild.required<Dialog>('dialog');
  protected readonly ecosystems = ECOSYSTEMS;
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected input: BlacklistInput = { ecosystem: 'npm', name: '', version: '', reason: '' };

  open(prefill: Partial<BlacklistInput> = {}): void {
    this.input = { ecosystem: 'npm', name: '', version: '', reason: '', ...prefill };
    this.error.set('');
    this.dialog().open();
  }

  protected placeholder(): string {
    return ({ npm: 'lodash', pypi: 'django', composer: 'monolog/monolog', docker: 'nginx ou ghcr.io/org/app' } as Record<EcosystemId, string>)[this.input.ecosystem];
  }

  protected async submit(): Promise<void> {
    this.busy.set(true);
    try {
      const entry = await this.api.addBlacklist({ ...this.input, name: this.input.name.trim(), version: this.input.version.trim() });
      this.toasts.ok(`${entry.name} ${entry.version} ajouté à la liste noire.`);
      this.added.emit(entry);
      this.dialog().close();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
