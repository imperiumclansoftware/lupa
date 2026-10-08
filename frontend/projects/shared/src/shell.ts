import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Auth } from './auth';
import { Icon } from './icon';
import { Logo, LogoName } from './logo';
import { ToastHost } from './toast';

export interface NavItem {
  path: string;
  label: string;
  icon: string;
  exact?: boolean;
}

/** Mise en page des pages connectées : navigation latérale (barre en haut sur mobile). */
@Component({
  selector: 'sas-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon, Logo, ToastHost],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="layout">
      <nav class="sidebar" aria-label="Navigation principale">
        <a class="brand" routerLink="/" [attr.aria-label]="brand() + ', tableau de bord'">
          <sas-logo [name]="logo()" [size]="38" />
          <span><strong class="wordmark">{{ brand() }}</strong><small>{{ product() }}</small></span>
        </a>
        <ul>
          @for (item of nav(); track item.path) {
            <li>
              <a [routerLink]="item.path" routerLinkActive="active" [routerLinkActiveOptions]="{ exact: !!item.exact }"
                ariaCurrentWhenActive="page">
                <sas-icon [name]="item.icon" />
                <span>{{ item.label }}</span>
              </a>
            </li>
          }
        </ul>
        <div class="footer">
          <div class="status" [class.online]="online()">
            <span class="pulse" aria-hidden="true"></span>
            <div>
              <strong>{{ online() ? 'Connecté à Internet' : 'Réseau isolé' }}</strong>
              <small>{{ online() ? 'Collecte et export' : 'Aucun accès sortant' }}</small>
            </div>
          </div>
          <div class="user">
            <span class="avatar" aria-hidden="true">{{ initial() }}</span>
            <span class="name">{{ auth.user()?.username }}</span>
            <button type="button" class="logout" (click)="logout()" aria-label="Déconnexion" title="Déconnexion"><sas-icon name="logout" [size]="17" /></button>
          </div>
          <span class="family"><sas-logo name="lupa" [size]="15" [bare]="true" />Lupa · Rémus &amp; Romulus</span>
        </div>
      </nav>
      <main class="main"><router-outlet /></main>
    </div>
    <sas-toasts />
  `,
  styles: `
    .layout { display: grid; grid-template-columns: var(--sidebar) minmax(0, 1fr); min-height: 100dvh; }
    .sidebar { position: sticky; top: 0; height: 100dvh; display: flex; flex-direction: column; gap: 26px; padding: 22px 14px 18px;
      color: rgb(255 255 255 / 0.92);
      background:
        radial-gradient(420px 260px at 0% 0%, rgb(255 255 255 / 0.08), transparent 70%),
        linear-gradient(180deg, var(--sidebar-bg-2), var(--sidebar-bg) 46%); }
    .brand { display: flex; align-items: center; gap: 12px; padding: 4px 8px; color: inherit; text-decoration: none; }
    .brand sas-logo { --accent: rgb(255 255 255 / 0.14); --on-accent: #fff; filter: drop-shadow(0 6px 12px rgb(0 0 0 / 0.25)); }
    .brand strong { display: block; font-size: 1.2rem; line-height: 1.15; color: #fff; }
    .brand small { color: rgb(255 255 255 / 0.62); font-size: 0.8rem; }

    ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 3px; }
    li a { position: relative; display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px;
      color: rgb(255 255 255 / 0.68); text-decoration: none; font-weight: 600; font-size: 0.92rem;
      transition-property: background-color, color; transition-duration: 140ms; }
    li a:hover { background: rgb(255 255 255 / 0.07); color: #fff; }
    li a.active { background: rgb(255 255 255 / 0.13); color: #fff; box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.08); }
    li a.active::before { content: ''; position: absolute; left: -14px; top: 10px; bottom: 10px; width: 3px; border-radius: 0 3px 3px 0; background: #fff; }

    .footer { margin-top: auto; display: grid; gap: 12px; }
    .status { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: rgb(0 0 0 / 0.18); }
    .status strong { display: block; font-size: 0.84rem; }
    .status small { color: rgb(255 255 255 / 0.55); font-size: 0.76rem; }
    .pulse { width: 9px; height: 9px; border-radius: 50%; background: #f0b44c; box-shadow: 0 0 0 4px rgb(240 180 76 / 0.2); flex: none; }
    .status.online .pulse { background: #5fd39f; box-shadow: 0 0 0 4px rgb(95 211 159 / 0.2); }
    .user { display: flex; align-items: center; gap: 10px; padding: 0 4px 0 6px; }
    .avatar { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; flex: none;
      background: rgb(255 255 255 / 0.16); color: #fff; font-weight: 700; font-size: 0.82rem; text-transform: uppercase; }
    .name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; font-size: 0.88rem; font-weight: 600; }
    .logout { display: grid; place-items: center; width: 34px; height: 34px; border: none; border-radius: 9px; cursor: pointer;
      background: transparent; color: rgb(255 255 255 / 0.65); transition-property: background-color, color; transition-duration: 140ms; }
    .logout:hover { background: rgb(255 255 255 / 0.1); color: #fff; }
    .family { display: inline-flex; align-items: center; gap: 6px; padding: 0 6px; color: rgb(255 255 255 / 0.4); font-size: 0.74rem; }
    .main { min-width: 0; }

    @media (max-width: 860px) {
      .layout { grid-template-columns: minmax(0, 1fr); }
      .sidebar { position: sticky; z-index: 10; min-width: 0; height: auto; flex-direction: row; flex-wrap: wrap; align-items: center;
        gap: 8px 12px; padding: 10px 12px; background: linear-gradient(90deg, var(--sidebar-bg), var(--sidebar-bg-2)); }
      .brand sas-logo { filter: none; }
      ul { display: flex; overflow-x: auto; order: 3; width: 100%; scrollbar-width: none; }
      li a { white-space: nowrap; padding: 8px 12px; }
      li a.active::before { display: none; }
      .footer { margin: 0 0 0 auto; grid-auto-flow: column; align-items: center; }
      .status, .family, .name, .avatar { display: none; }
    }
  `,
})
export class Shell {
  readonly brand = input.required<string>();
  readonly product = input.required<string>();
  readonly logo = input.required<LogoName>();
  readonly nav = input.required<NavItem[]>();
  readonly online = input(true);
  protected readonly auth = inject(Auth);
  protected readonly initial = computed(() => (this.auth.user()?.username ?? '?').charAt(0));
  private readonly router = inject(Router);

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/connexion']);
  }
}
