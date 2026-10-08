import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DateTimePipe, Dialog, Icon, Toasts, errorMessage } from '@sas/shared';
import { MirrorApi, TrustedKey } from '../api';
import { viewChild } from '@angular/core';

@Component({
  imports: [FormsModule, Icon, Dialog, DateTimePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Réglages</h1>
          <p>Collecteurs dont les bundles sont acceptés. Un bundle signé par une autre clé est refusé.</p>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      <section class="card">
        <header class="card-header"><div class="row"><sas-icon name="shield" /><h2>Collecteurs approuvés</h2></div></header>
        @if (keys().length === 0) {
          <div class="empty"><sas-icon name="key" [size]="28" /><h3>Aucun collecteur approuvé</h3>
            <p>Le premier import propose d’approuver le collecteur après vérification de son empreinte, ou ajoutez sa clé ci-dessous.</p></div>
        } @else {
          <ul class="keys">
            @for (k of keys(); track k.id) {
              <li>
                <div>
                  <strong>{{ k.label }}</strong>
                  <div class="fingerprint small">{{ k.fingerprint }}</div>
                  <div class="subtle">Approuvé le {{ k.createdAt | datetime }}</div>
                </div>
                <button type="button" class="btn btn-sm btn-danger btn-ghost" (click)="askRemove(k)"><sas-icon name="trash" [size]="15" />Retirer</button>
              </li>
            }
          </ul>
        }
      </section>

      <form class="card card-body stack" (ngSubmit)="add()">
        <h2>Ajouter une clé</h2>
        <p class="muted">Copiez la clé publique depuis le collecteur (Réglages › Signature des bundles).</p>
        <div class="field">
          <label for="label">Nom</label>
          <input class="input" id="label" name="label" [(ngModel)]="label" placeholder="ex. Collecteur du bureau" maxlength="120" />
        </div>
        <div class="field">
          <label for="key">Clé publique</label>
          <input class="input mono" id="key" name="key" [(ngModel)]="publicKey" required spellcheck="false" autocomplete="off" placeholder="Base64, 44 caractères" />
        </div>
        <div><button class="btn btn-primary" type="submit" [disabled]="!publicKey.trim()"><sas-icon name="plus" />Ajouter</button></div>
      </form>
    </div>

    <sas-dialog heading="Retirer ce collecteur ?">
      <p>Les paquets déjà importés restent servis. Les prochains bundles de <strong>{{ removing()?.label }}</strong> seront refusés jusqu’à une nouvelle approbation.</p>
      <div dialog-actions>
        <button type="button" class="btn" (click)="dialog().close()">Annuler</button>
        <button type="button" class="btn btn-primary" (click)="remove()">Retirer</button>
      </div>
    </sas-dialog>
  `,
  styles: `
    .keys { list-style: none; margin: 0; padding: 0; }
    .keys li { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--border); }
    .keys li:last-child { border-bottom: none; }
    .small { font-size: 0.9rem; margin: 2px 0; }
  `,
})
export default class Settings implements OnInit {
  private readonly api = inject(MirrorApi);
  private readonly toasts = inject(Toasts);
  protected readonly dialog = viewChild.required(Dialog);
  protected readonly keys = signal<TrustedKey[]>([]);
  protected readonly removing = signal<TrustedKey | null>(null);
  protected readonly error = signal('');
  protected label = '';
  protected publicKey = '';

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  private async load(): Promise<void> {
    try {
      this.keys.set(await this.api.keys());
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async add(): Promise<void> {
    try {
      const key = await this.api.addKey(this.publicKey.trim(), this.label.trim());
      this.toasts.ok(`Clé ajoutée : ${key.fingerprint}`);
      this.label = this.publicKey = '';
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected askRemove(key: TrustedKey): void {
    this.removing.set(key);
    this.dialog().open();
  }

  protected async remove(): Promise<void> {
    const key = this.removing();
    if (!key) return;
    try {
      await this.api.deleteKey(key.id);
      this.dialog().close();
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
