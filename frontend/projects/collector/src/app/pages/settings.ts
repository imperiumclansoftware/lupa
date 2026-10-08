import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CopyButton, Icon, Toasts, errorMessage } from '@sas/shared';
import { CollectorApi, Identity } from '../api';

@Component({
  imports: [FormsModule, CopyButton, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Réglages</h1>
          <p>Identité du collecteur et clé qui signe les bundles.</p>
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (identity(); as id) {
        <form class="card card-body stack" (ngSubmit)="save()">
          <h2>Nom du collecteur</h2>
          <div class="field name">
            <label for="name">Nom affiché dans Romulus et dans le nom des fichiers</label>
            <div class="row">
              <input class="input" id="name" name="name" maxlength="60" required [(ngModel)]="name" />
              <button class="btn btn-primary" type="submit" [disabled]="busy() || name.trim() === id.name">Enregistrer</button>
            </div>
          </div>
        </form>

        <section class="card card-body stack">
          <div class="row"><sas-icon name="shield" /><h2>Signature des bundles</h2></div>
          <p class="muted">
            Chaque bundle est signé avec la clé secrète de ce collecteur (Ed25519). Romulus refuse tout bundle
            dont la signature ne correspond pas à une clé qu’il a approuvée : un fichier modifié ou fabriqué ailleurs est rejeté.
          </p>
          <div class="field">
            <span class="label">Empreinte de la clé</span>
            <div class="row">
              <span class="fingerprint">{{ id.fingerprint }}</span>
              <sas-copy [text]="id.fingerprint" />
            </div>
            <span class="hint">Au premier import, Romulus affiche cette même empreinte : vérifiez qu’elle est identique avant d’approuver.</span>
          </div>
          <div class="field">
            <span class="label">Clé publique</span>
            <pre class="kbd-block">{{ id.publicKey }}</pre>
            <div><sas-copy [text]="id.publicKey" label="Copier la clé publique" /></div>
            <span class="hint">Pour approuver ce collecteur à l’avance : Romulus › Réglages › Ajouter une clé.</span>
          </div>
        </section>

        <section class="card card-body stack">
          <h2>Emplacements</h2>
          <div class="kv">
            <span class="subtle">Dossier d’export</span><code>{{ exportDir() }}</code>
            <span class="subtle">Stockage</span><code>{{ storageDir() }}</code>
          </div>
        </section>
      }
    </div>
  `,
  styles: `
    .name { max-width: 520px; }
    .name .row { align-items: stretch; }
    .kv { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
    .kv code { overflow-wrap: anywhere; }
  `,
})
export default class Settings implements OnInit {
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  protected readonly identity = signal<Identity | null>(null);
  protected readonly exportDir = signal('');
  protected readonly storageDir = signal('');
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected name = '';

  async ngOnInit(): Promise<void> {
    try {
      this.apply(await this.api.settings());
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      this.apply(await this.api.saveSettings(this.name.trim()));
      this.toasts.ok('Nom enregistré.');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  private apply(s: { identity: Identity; exportDir: string; storageDir: string }): void {
    this.identity.set(s.identity);
    this.name = s.identity.name;
    this.exportDir.set(s.exportDir);
    this.storageDir.set(s.storageDir);
  }
}
