import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { errorMessage } from './api';
import { Auth } from './auth';
import { Icon } from './icon';

@Component({
  selector: 'sas-login-page',
  imports: [FormsModule, Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <div class="brand">
        <span class="logo"><sas-icon [name]="icon()" [size]="22" /></span>
        <div>
          <strong>Sas</strong>
          <span>{{ product() }}</span>
        </div>
      </div>

      <form class="card card-body" (ngSubmit)="submit()">
        <h1>Connexion</h1>
        @if (error()) { <div class="alert alert-danger" role="alert">{{ error() }}</div> }
        <div class="field">
          <label for="username">Identifiant</label>
          <input class="input" id="username" name="username" autocomplete="username" required
            [(ngModel)]="username" autofocus />
        </div>
        <div class="field">
          <label for="current-password">Mot de passe</label>
          <input class="input" id="current-password" name="password" type="password" autocomplete="current-password"
            required [(ngModel)]="password" />
        </div>
        <button class="btn btn-primary" type="submit" [disabled]="busy()">{{ busy() ? 'Connexion…' : 'Se connecter' }}</button>
        <p class="subtle">Pas encore de compte ? Créez-le sur le serveur : <code>{{ command() }}</code></p>
      </form>
    </main>
  `,
  styles: `
    .login { min-height: 100dvh; display: grid; place-content: center; gap: 24px; padding: 24px; width: min(400px, 100%); margin: 0 auto; }
    .brand { display: flex; align-items: center; gap: 12px; justify-content: center; }
    .brand strong { display: block; font-size: 1.15rem; }
    .brand span { color: var(--text-2); font-size: 0.88rem; }
    .logo { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--accent); color: var(--on-accent, #fff); }
    form { display: grid; gap: 16px; width: min(400px, calc(100vw - 48px)); }
    code { overflow-wrap: anywhere; }
  `,
})
export class LoginPage {
  readonly product = input.required<string>();
  readonly icon = input('lock');
  readonly command = input('make user');
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  protected username = '';
  protected password = '';
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  protected async submit(): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await this.auth.login(this.username, this.password);
      await this.router.navigateByUrl(this.route.snapshot.queryParamMap.get('retour') ?? '/');
    } catch (e) {
      this.error.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }
}
