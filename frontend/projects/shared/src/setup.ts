import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { Auth } from './auth';
import { Icon } from './icon';
import { Logo, LogoName } from './logo';
import { Toasts } from './toast';

type Step = 1 | 2 | 3;

/**
 * Assistant d'installation (aucun compte n'existe) : code d'installation lu sur le serveur,
 * compte administrateur, puis réglage propre à l'application.
 *  - variant « collector » : nom du collecteur (affiché dans Romulus et dans le nom des bundles) ;
 *  - variant « mirror » : clé publique d'un collecteur à approuver d'emblée (facultatif).
 */
@Component({
  selector: 'sas-setup-page',
  imports: [FormsModule, Icon, Logo],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="setup">
      <sas-logo class="watermark" [name]="logo()" [size]="520" [bare]="true" aria-hidden="true" />
      <div class="column">
        <header class="brand">
          <sas-logo [name]="logo()" [size]="56" />
          <h1 class="wordmark">{{ brand() }}</h1>
          <p>Installation · {{ product() }}</p>
        </header>

        <ol class="steps" aria-label="Étapes">
          @for (s of stepLabels; track $index; let i = $index) {
            <li [class.done]="step() > i + 1" [class.current]="step() === i + 1" [attr.aria-current]="step() === i + 1 ? 'step' : null">
              <span class="dot">@if (step() > i + 1) { <sas-icon name="check" [size]="13" /> } @else { {{ i + 1 }} }</span>
              <span class="label">{{ s }}</span>
            </li>
          }
        </ol>

        <form class="card" (ngSubmit)="next()">
          @if (error()) { <div class="alert alert-danger" role="alert">{{ error() }}</div> }

          @switch (step()) {
            @case (1) {
              <h2>Code d’installation</h2>
              <p class="muted">Pour prouver que vous administrez ce serveur, saisissez le code affiché dans ses journaux au démarrage. Il ne sert qu’une fois.</p>
              <div class="field">
                <label for="code">Code</label>
                <input class="input code" id="code" name="code" required autocomplete="one-time-code" autocapitalize="characters"
                  spellcheck="false" placeholder="XXXX-XXXX-XXXX" [(ngModel)]="code" autofocus />
              </div>
              <div class="where">
                <span class="label">Où le trouver ?</span>
                <pre class="kbd-block">{{ codeCommand() }}</pre>
              </div>
            }
            @case (2) {
              <h2>Compte administrateur</h2>
              <p class="muted">Ce compte gère {{ brand() }}. D’autres comptes pourront être créés ensuite en ligne de commande.</p>
              <div class="field">
                <label for="username">Identifiant</label>
                <input class="input" id="username" name="username" required autocomplete="username" [(ngModel)]="username" autofocus />
              </div>
              <div class="field">
                <label for="new-password">Mot de passe</label>
                <input class="input" id="new-password" name="password" type="password" required autocomplete="new-password" minlength="12"
                  [ngModel]="password()" (ngModelChange)="password.set($event)" />
                <div class="strength" [attr.data-level]="strength()" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <span class="hint">12 caractères minimum. Une phrase de passe est idéale.</span>
              </div>
              <div class="field">
                <label for="confirm">Confirmation</label>
                <input class="input" id="confirm" name="confirm" type="password" required autocomplete="new-password" [(ngModel)]="confirm" />
              </div>
            }
            @case (3) {
              @if (variant() === 'collector') {
                <h2>Nom du collecteur</h2>
                <p class="muted">Affiché dans Romulus à chaque import et utilisé dans le nom des fichiers de bundle.</p>
                <div class="field">
                  <label for="name">Nom</label>
                  <input class="input" id="name" name="name" maxlength="60" [(ngModel)]="name" placeholder="ex. Rémus bureau" autofocus />
                </div>
              } @else {
                <h2>Collecteur approuvé <span class="subtle">(facultatif)</span></h2>
                <p class="muted">Collez la clé publique de Rémus (Réglages › Signature des bundles) pour accepter ses bundles dès le premier import.
                  Sinon, vous comparerez son empreinte lors du premier import.</p>
                <div class="field">
                  <label for="key">Clé publique</label>
                  <input class="input mono" id="key" name="key" spellcheck="false" autocomplete="off" placeholder="Base64, 44 caractères" [(ngModel)]="publicKey" />
                </div>
                <div class="field">
                  <label for="label">Nom du collecteur</label>
                  <input class="input" id="label" name="label" maxlength="120" placeholder="ex. Rémus bureau" [(ngModel)]="keyLabel" />
                </div>
              }
            }
          }

          <div class="buttons">
            @if (step() > 1) {
              <button type="button" class="btn btn-ghost" (click)="back()"><sas-icon name="back" [size]="16" />Précédent</button>
            }
            <button type="submit" class="btn btn-primary" [disabled]="busy()">
              {{ step() === 3 ? (busy() ? 'Installation…' : 'Terminer l’installation') : 'Continuer' }}
              @if (step() < 3) { <sas-icon name="chevron" [size]="16" /> }
            </button>
          </div>
        </form>
      </div>
    </main>
  `,
  styles: `
    .setup { position: relative; overflow: hidden; min-height: 100dvh; display: grid; place-items: center; padding: 32px 16px;
      background:
        radial-gradient(700px 420px at 15% 10%, rgb(255 255 255 / 0.12), transparent 70%),
        linear-gradient(150deg, var(--sidebar-bg-2), var(--sidebar-bg) 60%); }
    .watermark { position: absolute; right: -90px; bottom: -110px; color: #fff; opacity: 0.07; pointer-events: none; }
    .column { position: relative; display: grid; gap: 22px; width: min(480px, 100%); }
    .brand { display: grid; justify-items: center; gap: 4px; text-align: center; color: #fff; }
    .brand sas-logo { --accent: rgb(255 255 255 / 0.16); --on-accent: #fff; margin-bottom: 6px; filter: drop-shadow(0 10px 24px rgb(0 0 0 / 0.3)); }
    .brand h1 { font-size: 2rem; }
    .brand p { color: rgb(255 255 255 / 0.7); }

    .steps { list-style: none; margin: 0; padding: 0; display: flex; justify-content: center; gap: 8px; }
    .steps li { display: flex; align-items: center; gap: 8px; color: rgb(255 255 255 / 0.55); font-size: 0.84rem; font-weight: 600; }
    .steps li + li::before { content: ''; width: 22px; height: 1px; background: rgb(255 255 255 / 0.3); }
    .dot { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; font-size: 0.78rem;
      background: rgb(255 255 255 / 0.12); box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.25); }
    .steps li.current { color: #fff; }
    .steps li.current .dot { background: #fff; color: var(--sidebar-bg); box-shadow: none; }
    .steps li.done .dot { background: rgb(255 255 255 / 0.3); color: #fff; }

    form { display: grid; gap: 16px; padding: 28px; border-radius: 22px; box-shadow: 0 30px 60px -20px rgb(0 0 0 / 0.5); }
    .code { font-family: var(--mono); font-size: 1.15rem; letter-spacing: 0.12em; text-transform: uppercase; text-align: center; }
    .where { display: grid; gap: 6px; }
    .buttons { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
    .buttons .btn-primary { min-height: 42px; padding: 0 18px; }

    .strength { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }
    .strength span { height: 4px; border-radius: 4px; background: var(--surface-3); transition: background-color 160ms; }
    .strength[data-level='1'] span:nth-child(-n + 1) { background: var(--danger); }
    .strength[data-level='2'] span:nth-child(-n + 2) { background: var(--warn); }
    .strength[data-level='3'] span:nth-child(-n + 3) { background: var(--ok); }
    .strength[data-level='4'] span { background: var(--ok); }
    @media (max-width: 480px) { .steps .label { display: none; } }
  `,
})
export class SetupPage implements OnInit {
  readonly brand = input.required<string>();
  readonly product = input.required<string>();
  readonly logo = input.required<LogoName>();
  readonly variant = input.required<'collector' | 'mirror'>();
  readonly codeCommand = input.required<string>();

  private readonly auth = inject(Auth);
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly toasts = inject(Toasts);

  protected readonly stepLabels = ['Code', 'Compte', 'Configuration'];
  protected readonly step = signal<Step>(1);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly password = signal('');
  protected readonly strength = computed(() => {
    const p = this.password();
    if (!p) return 0;
    const variety = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(p)).length;
    if (p.length < 12) return 1;
    return Math.min(4, (p.length >= 16 ? 2 : 1) + (variety >= 3 ? 2 : variety >= 2 ? 1 : 0));
  });

  protected code = '';
  protected username = 'admin';
  protected confirm = '';
  protected name = '';
  protected publicKey = '';
  protected keyLabel = '';

  async ngOnInit(): Promise<void> {
    try {
      const state = await firstValueFrom(this.http.get<{ required: boolean; defaults?: { name?: string } }>('/api/setup'));
      this.name = state.defaults?.name ?? '';
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected back(): void {
    this.error.set('');
    this.step.update((s) => (s > 1 ? ((s - 1) as Step) : s));
  }

  protected async next(): Promise<void> {
    this.error.set('');
    if (this.step() === 1) {
      if (this.code.replace(/[^A-Za-z0-9]/g, '').length !== 12) {
        this.error.set('Le code compte 12 caractères (ex. ABCD-EFGH-JKLM).');
        return;
      }
      this.step.set(2);
      return;
    }
    if (this.step() === 2) {
      if (this.username.trim().length < 3) {
        this.error.set('Identifiant : 3 caractères minimum.');
      } else if (this.password().length < 12) {
        this.error.set('Mot de passe : 12 caractères minimum.');
      } else if (this.password() !== this.confirm) {
        this.error.set('Les deux mots de passe ne correspondent pas.');
      } else {
        this.step.set(3);
      }
      return;
    }

    this.busy.set(true);
    try {
      await this.auth.setup({
        code: this.code,
        username: this.username.trim(),
        password: this.password(),
        ...(this.variant() === 'collector'
          ? { name: this.name.trim() || null }
          : { publicKey: this.publicKey.trim() || null, keyLabel: this.keyLabel.trim() || null }),
      });
      this.toasts.ok(`Installation terminée. Bienvenue dans ${this.brand()} !`);
      await this.router.navigateByUrl('/');
    } catch (e) {
      const message = errorMessage(e);
      this.error.set(message);
      // Code refusé : retour à la première étape
      if (message.includes('Code d’installation') || message.includes("Code d'installation")) this.step.set(1);
    } finally {
      this.busy.set(false);
    }
  }
}
