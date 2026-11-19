import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, WritableSignal, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { Auth, SessionUser } from './auth';
import { CopyButton } from './copy-button';
import { Icon } from './icon';
import { Toasts } from './toast';

interface Enrollment {
  secret: string;
  uri: string;
  qr: { size: number; path: string };
}

/** Son propre compte, quel que soit son rôle : mot de passe et double authentification (ADR 0009). */
@Component({
  selector: 'sas-account-page',
  imports: [FormsModule, Icon, CopyButton],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Mon compte</h1>
          <p>
            {{ auth.user()?.username }} · {{ auth.user()?.roleLabel }}.
            {{ roleHelp[auth.user()?.role ?? 'reader'] }}
          </p>
        </div>
      </header>

      <section class="card">
        <header class="card-header"><h2>Mot de passe</h2></header>
        <form class="card-body stack" (ngSubmit)="changePassword()">
          <div class="field">
            <label for="current">Mot de passe actuel</label>
            <input
              class="input"
              id="current"
              name="current"
              type="password"
              autocomplete="current-password"
              required
              [(ngModel)]="current"
            />
          </div>
          <div class="field">
            <label for="new-password">Nouveau mot de passe</label>
            <input
              class="input"
              id="new-password"
              name="password"
              type="password"
              autocomplete="new-password"
              required
              minlength="12"
              [(ngModel)]="password"
            />
            <small class="subtle"
              >12 caractères minimum ; une phrase de plusieurs mots est idéale.</small
            >
          </div>
          <div class="field">
            <label for="confirm">Confirmation</label>
            <input
              class="input"
              id="confirm"
              name="confirm"
              type="password"
              autocomplete="new-password"
              required
              [(ngModel)]="confirm"
            />
          </div>
          @if (passwordError()) {
            <div class="alert alert-danger" role="alert">{{ passwordError() }}</div>
          }
          <div>
            <button class="btn btn-primary" type="submit" [disabled]="busy()">
              Changer le mot de passe
            </button>
          </div>
        </form>
      </section>

      <section class="card">
        <header class="card-header">
          <h2>Double authentification</h2>
          @if (auth.user()?.twoFactor) {
            <span class="badge badge-accent"><sas-icon name="shield" [size]="14" />Activée</span>
          } @else {
            <span class="badge">Désactivée</span>
          }
        </header>
        <div class="card-body stack">
          @if (codes(); as list) {
            <div class="alert alert-warn" role="status">
              Notez ces codes de secours et rangez-les à l’abri : ils remplacent l’application si le
              téléphone est perdu. Chacun ne sert qu’une fois et ils ne seront plus affichés.
            </div>
            <ul class="codes">
              @for (c of list; track c) {
                <li class="mono">{{ c }}</li>
              }
            </ul>
            <div class="row">
              <sas-copy [text]="list.join('\\n')" label="Copier les codes" />
              <button class="btn" type="button" (click)="codes.set(null)">
                J’ai noté ces codes
              </button>
            </div>
          } @else if (enrollment(); as e) {
            <ol class="steps">
              <li>
                Scannez ce QR code avec une application d’authentification (FreeOTP, Aegis, Google
                Authenticator…).
              </li>
              <li>
                Ou saisissez la clé à la main : <span class="mono secret">{{ e.secret }}</span>
                <sas-copy [text]="e.secret.replaceAll(' ', '')" />
              </li>
              <li>Indiquez le code à six chiffres affiché par l’application.</li>
            </ol>
            <svg
              class="qr"
              [attr.viewBox]="'-4 -4 ' + (e.qr.size + 8) + ' ' + (e.qr.size + 8)"
              role="img"
              aria-label="QR code de la clé d’authentification"
            >
              <rect
                x="-4"
                y="-4"
                [attr.width]="e.qr.size + 8"
                [attr.height]="e.qr.size + 8"
                fill="#fff"
              />
              <path [attr.d]="e.qr.path" fill="#000" />
            </svg>
            <form class="row" (ngSubmit)="enable()">
              <label class="visually-hidden" for="totp">Code</label>
              <input
                class="input totp"
                id="totp"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="123 456"
                required
                [(ngModel)]="code"
              />
              <button class="btn btn-primary" type="submit" [disabled]="busy()">Activer</button>
              <button class="btn btn-ghost" type="button" (click)="enrollment.set(null)">
                Annuler
              </button>
            </form>
            @if (twoFactorError()) {
              <div class="alert alert-danger" role="alert">{{ twoFactorError() }}</div>
            }
          } @else {
            <p class="subtle">
              @if (auth.user()?.twoFactor) {
                À chaque connexion, un code de l’application d’authentification est demandé après le
                mot de passe.
                {{ auth.user()?.backupCodes }} code(s) de secours restant(s).
              } @else {
                Facultative : en plus du mot de passe, un code à six chiffres généré par une
                application du téléphone (elle fonctionne sans réseau). Un mot de passe volé ne
                suffit plus.
              }
            </p>
            <form class="row" (ngSubmit)="action()">
              <label class="visually-hidden" for="confirm-password">Mot de passe</label>
              <input
                class="input"
                id="confirm-password"
                name="password"
                type="password"
                autocomplete="current-password"
                placeholder="Votre mot de passe"
                required
                [(ngModel)]="confirmPassword"
              />
              @if (auth.user()?.twoFactor) {
                <button class="btn" type="submit" (click)="mode = 'codes'" [disabled]="busy()">
                  Nouveaux codes de secours
                </button>
                <button
                  class="btn btn-danger"
                  type="submit"
                  (click)="mode = 'disable'"
                  [disabled]="busy()"
                >
                  Désactiver
                </button>
              } @else {
                <button
                  class="btn btn-primary"
                  type="submit"
                  (click)="mode = 'setup'"
                  [disabled]="busy()"
                >
                  Activer la double authentification
                </button>
              }
            </form>
            @if (twoFactorError()) {
              <div class="alert alert-danger" role="alert">{{ twoFactorError() }}</div>
            }
          }
        </div>
      </section>
    </div>
  `,
  styles: `
    .stack {
      display: grid;
      gap: 14px;
      max-width: 560px;
    }
    .row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .qr {
      width: 220px;
      height: 220px;
      border-radius: 12px;
      shape-rendering: crispEdges;
    }
    .totp {
      width: 10ch;
      font-family: var(--mono, monospace);
      letter-spacing: 0.1em;
    }
    .secret {
      overflow-wrap: anywhere;
    }
    .steps {
      margin: 0;
      padding-left: 20px;
      display: grid;
      gap: 6px;
    }
    .codes {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 8px;
    }
    .codes li {
      padding: 6px 10px;
      border-radius: 8px;
      background: var(--surface-2);
    }
  `,
})
export class AccountPage {
  protected readonly auth = inject(Auth);
  private readonly http = inject(HttpClient);
  private readonly toasts = inject(Toasts);
  protected readonly roleHelp: Record<string, string> = {
    admin: 'Tous les droits, dont les comptes et les réglages.',
    operator: 'Travail courant : listes, analyses et transferts.',
    reader: 'Consultation seulement.',
  };
  protected current = '';
  protected password = '';
  protected confirm = '';
  protected confirmPassword = '';
  protected code = '';
  protected mode: 'setup' | 'codes' | 'disable' = 'setup';
  protected readonly busy = signal(false);
  protected readonly passwordError = signal('');
  protected readonly twoFactorError = signal('');
  protected readonly enrollment = signal<Enrollment | null>(null);
  protected readonly codes = signal<string[] | null>(null);

  protected async changePassword(): Promise<void> {
    this.passwordError.set('');
    if (this.password !== this.confirm) {
      this.passwordError.set('La confirmation ne correspond pas au nouveau mot de passe.');
      return;
    }
    await this.run(this.passwordError, async () => {
      this.auth.update(
        await this.post<SessionUser>('/api/account/password', {
          current: this.current,
          password: this.password,
        }),
      );
      this.current = this.password = this.confirm = '';
      this.toasts.ok('Mot de passe changé.');
    });
  }

  protected async action(): Promise<void> {
    const password = this.confirmPassword;
    await this.run(this.twoFactorError, async () => {
      if (this.mode === 'setup') {
        this.enrollment.set(await this.post<Enrollment>('/api/account/2fa/setup', { password }));
      } else if (this.mode === 'codes') {
        const result = await this.post<SessionUser & { codes: string[] }>(
          '/api/account/2fa/backup-codes',
          { password },
        );
        this.auth.update(result);
        this.codes.set(result.codes);
      } else {
        this.auth.update(await this.post<SessionUser>('/api/account/2fa/disable', { password }));
        this.toasts.ok('Double authentification désactivée.');
      }
      this.confirmPassword = '';
    });
  }

  protected async enable(): Promise<void> {
    await this.run(this.twoFactorError, async () => {
      const result = await this.post<SessionUser & { codes: string[] }>('/api/account/2fa/enable', {
        code: this.code,
      });
      this.auth.update(result);
      this.enrollment.set(null);
      this.codes.set(result.codes);
      this.code = '';
      this.toasts.ok('Double authentification activée.');
    });
  }

  private post<T>(url: string, body: object): Promise<T> {
    return firstValueFrom(this.http.post<T>(url, body));
  }

  private async run(error: WritableSignal<string>, task: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    error.set('');
    try {
      await task();
    } catch (e) {
      error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
