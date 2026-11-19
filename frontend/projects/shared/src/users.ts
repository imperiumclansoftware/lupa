import { HttpClient } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { Page, allPages, errorMessage } from './api';
import { Auth, Role, SessionUser } from './auth';
import { Dialog } from './dialog';
import { Icon } from './icon';
import { Toasts } from './toast';

type Account = SessionUser;

/** Comptes, réservés aux administrateurs (ADR 0010). L'API garde au moins un administrateur. */
@Component({
  selector: 'sas-users-page',
  imports: [FormsModule, Icon, Dialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Comptes</h1>
          <p>
            Lecteur : consulte tout. Opérateur : en plus, le travail courant ({{ operatorHelp() }}).
            Administrateur : en plus, les comptes, les réglages et la sécurité.
          </p>
        </div>
      </header>

      @if (error()) {
        <div class="alert alert-danger" role="alert">{{ error() }}</div>
      }

      <section class="card">
        <header class="card-header"><h2>Nouveau compte</h2></header>
        <form class="card-body create" (ngSubmit)="create()">
          <div class="field">
            <label for="new-username">Identifiant</label>
            <input
              class="input"
              id="new-username"
              name="username"
              autocomplete="off"
              required
              [(ngModel)]="username"
            />
          </div>
          <div class="field">
            <label for="new-role">Rôle</label>
            <select class="input" id="new-role" name="role" [(ngModel)]="role">
              @for (r of roles; track r.id) {
                <option [value]="r.id">{{ r.label }}</option>
              }
            </select>
          </div>
          <div class="field">
            <label for="new-password">Mot de passe provisoire</label>
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
          </div>
          <div>
            <button class="btn btn-primary" type="submit" [disabled]="busy()">Créer</button>
          </div>
        </form>
        <p class="card-body subtle hint">
          Transmettez le mot de passe provisoire de vive voix : son titulaire le remplace depuis «
          Mon compte ».
        </p>
      </section>

      <section class="card">
        <header class="card-header">
          <h2>{{ users().length }} compte(s)</h2>
        </header>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Identifiant</th>
                <th>Rôle</th>
                <th>Double authentification</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @for (u of users(); track u.id) {
                <tr>
                  <td>
                    <strong>{{ u.username }}</strong>
                    @if (u.id === auth.user()?.id) {
                      <span class="subtle"> (vous)</span>
                    }
                  </td>
                  <td>
                    <label class="visually-hidden" [for]="'role-' + u.id"
                      >Rôle de {{ u.username }}</label
                    >
                    <select
                      class="input input-sm"
                      [id]="'role-' + u.id"
                      [ngModel]="u.role"
                      (ngModelChange)="changeRole(u, $event)"
                      [disabled]="u.id === auth.user()?.id"
                    >
                      @for (r of roles; track r.id) {
                        <option [value]="r.id">{{ r.label }}</option>
                      }
                    </select>
                  </td>
                  <td>
                    @if (u.twoFactor) {
                      <span class="badge badge-accent"
                        ><sas-icon name="shield" [size]="14" />Activée</span
                      >
                      <button
                        class="btn btn-ghost btn-sm"
                        type="button"
                        (click)="resetTwoFactor(u)"
                      >
                        Réinitialiser
                      </button>
                    } @else {
                      <span class="subtle">Désactivée</span>
                    }
                  </td>
                  <td class="right actions">
                    <button class="btn btn-ghost btn-sm" type="button" (click)="askPassword(u)">
                      Mot de passe
                    </button>
                    @if (u.id !== auth.user()?.id) {
                      <button
                        class="btn btn-ghost btn-icon btn-sm"
                        type="button"
                        (click)="remove(u)"
                        [attr.aria-label]="'Supprimer ' + u.username"
                        title="Supprimer"
                      >
                        <sas-icon name="trash" />
                      </button>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <sas-dialog
      #passwordDialog
      [heading]="'Nouveau mot de passe pour ' + (target()?.username ?? '')"
    >
      <form id="reset-form" class="field" (ngSubmit)="resetPassword()">
        <label for="reset-password">Mot de passe provisoire (12 caractères minimum)</label>
        <input
          class="input"
          id="reset-password"
          name="password"
          type="password"
          autocomplete="new-password"
          required
          minlength="12"
          [(ngModel)]="resetValue"
        />
      </form>
      <div dialog-actions>
        <button type="button" class="btn" (click)="passwordDialog.close()">Annuler</button>
        <button type="submit" form="reset-form" class="btn btn-primary" [disabled]="busy()">
          Enregistrer
        </button>
      </div>
    </sas-dialog>
  `,
  styles: `
    .create {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
      align-items: end;
    }
    .hint {
      padding-top: 0;
    }
    .actions {
      white-space: nowrap;
    }
    .input-sm {
      width: auto;
      min-height: 32px;
      padding-block: 4px;
    }
  `,
})
export class UsersPage {
  protected readonly auth = inject(Auth);
  private readonly http = inject(HttpClient);
  private readonly toasts = inject(Toasts);
  protected readonly passwordDialog = viewChild.required<Dialog>('passwordDialog');
  protected readonly roles: { id: Role; label: string }[] = [
    { id: 'reader', label: 'Lecteur' },
    { id: 'operator', label: 'Opérateur' },
    { id: 'admin', label: 'Administrateur' },
  ];
  /** Travail courant de l'opérateur, selon l'application (donnée de la route) */
  readonly operatorHelp = input('listes, analyses, exports');
  protected readonly users = signal<Account[]>([]);
  protected readonly error = signal('');
  protected readonly busy = signal(false);
  protected readonly target = signal<Account | null>(null);
  protected username = '';
  protected role: Role = 'reader';
  protected password = '';
  protected resetValue = '';

  constructor() {
    void this.load();
  }

  protected async create(): Promise<void> {
    await this.run(async () => {
      await firstValueFrom(
        this.http.post('/api/users', {
          username: this.username,
          role: this.role,
          password: this.password,
        }),
      );
      this.toasts.ok(`Compte « ${this.username} » créé.`);
      this.username = this.password = '';
      await this.load();
    });
  }

  protected async changeRole(user: Account, role: Role): Promise<void> {
    await this.run(async () => {
      await firstValueFrom(this.http.put(`/api/users/${user.id}`, { role }));
      this.toasts.ok(`${user.username} : rôle modifié.`);
    });
    await this.load();
  }

  protected askPassword(user: Account): void {
    this.target.set(user);
    this.resetValue = '';
    this.passwordDialog().open();
  }

  protected async resetPassword(): Promise<void> {
    const user = this.target();
    if (!user) return;
    await this.run(async () => {
      await firstValueFrom(this.http.put(`/api/users/${user.id}`, { password: this.resetValue }));
      this.passwordDialog().close();
      this.toasts.ok(`${user.username} : nouveau mot de passe enregistré.`);
    });
    this.resetValue = '';
  }

  protected async resetTwoFactor(user: Account): Promise<void> {
    if (
      !confirm(
        `Désactiver la double authentification de ${user.username} ? Il se connectera avec son seul mot de passe, puis pourra la réactiver.`,
      )
    )
      return;
    await this.run(async () => {
      await firstValueFrom(this.http.delete(`/api/users/${user.id}/2fa`));
      this.toasts.ok(`${user.username} : double authentification réinitialisée.`);
      await this.load();
    });
  }

  protected async remove(user: Account): Promise<void> {
    if (!confirm(`Supprimer le compte ${user.username} ?`)) return;
    await this.run(async () => {
      await firstValueFrom(this.http.delete(`/api/users/${user.id}`));
      this.toasts.ok(`Compte « ${user.username} » supprimé.`);
      await this.load();
    });
  }

  private async load(): Promise<void> {
    try {
      const { items } = await allPages((page) =>
        firstValueFrom(this.http.get<Page<Account>>('/api/users', { params: { page } })),
      );
      this.users.set(items as Account[]);
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private async run(task: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await task();
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
