import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { errorMessage } from './api';
import { Auth } from './auth';
import { Logo, LogoName } from './logo';

@Component({
  selector: 'sas-login-page',
  imports: [FormsModule, Logo],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="login">
      <header class="brand">
        <sas-logo [name]="logo()" [size]="64" />
        <h1 class="wordmark">{{ brand() }}</h1>
        <p>{{ product() }}</p>
      </header>

      <form class="card card-body" (ngSubmit)="submit()">
        <h2>Connexion</h2>
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
      <footer class="family"><sas-logo name="lupa" [size]="16" [bare]="true" />Lupa · Rémus collecte, Romulus sert</footer>
    </main>
  `,
  styles: `
    .login { min-height: 100dvh; display: grid; place-content: center; gap: 24px; padding: 24px; width: min(400px, 100%); margin: 0 auto; }
    .brand { display: grid; justify-items: center; gap: 6px; text-align: center; }
    .brand sas-logo { margin-bottom: 6px; }
    .brand h1 { font-size: 2rem; }
    .brand p { color: var(--text-2); }
    .family { display: inline-flex; align-items: center; justify-content: center; gap: 6px; color: var(--text-3); font-size: 0.8rem; }
    form { display: grid; gap: 16px; width: min(400px, calc(100vw - 48px)); }
    code { overflow-wrap: anywhere; }
  `,
})
export class LoginPage {
  readonly brand = input.required<string>();
  readonly product = input.required<string>();
  readonly logo = input.required<LogoName>();
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
