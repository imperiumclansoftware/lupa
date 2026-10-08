import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Auth } from './auth';
import { Icon } from './icon';
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
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Icon, ToastHost],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="layout">
      <nav class="sidebar" aria-label="Navigation principale">
        <a class="brand" routerLink="/">
          <span class="logo"><sas-icon [name]="icon()" [size]="20" /></span>
          <span><strong>Sas</strong><small>{{ product() }}</small></span>
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
          <span class="badge" [class.badge-ok]="online()" [class.badge-warn]="!online()">
            <span class="dot"></span>{{ online() ? 'Connecté à Internet' : 'Réseau isolé' }}
          </span>
          <div class="user">
            <span class="subtle">{{ auth.user()?.username }}</span>
            <button type="button" class="btn btn-ghost btn-sm" (click)="logout()"><sas-icon name="logout" [size]="16" />Déconnexion</button>
          </div>
        </div>
      </nav>
      <main class="main"><router-outlet /></main>
    </div>
    <sas-toasts />
  `,
  styles: `
    .layout { display: grid; grid-template-columns: var(--sidebar) 1fr; min-height: 100dvh; }
    .sidebar { position: sticky; top: 0; height: 100dvh; display: flex; flex-direction: column; gap: 20px; padding: 20px 14px;
      background: var(--surface); border-right: 1px solid var(--border); }
    .brand { display: flex; align-items: center; gap: 10px; padding: 4px 8px; color: inherit; text-decoration: none; }
    .brand strong { display: block; line-height: 1.2; }
    .brand small { color: var(--text-2); font-size: 0.8rem; }
    .logo { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: var(--accent); color: var(--on-accent, #fff); flex: none; }
    ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
    li a { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: var(--radius-s); color: var(--text-2);
      text-decoration: none; font-weight: 600; font-size: 0.92rem; transition: background-color 120ms ease, color 120ms ease; }
    li a:hover { background: var(--surface-2); color: var(--text); }
    li a.active { background: var(--accent-soft); color: var(--accent-strong); }
    .footer { margin-top: auto; display: grid; gap: 10px; padding: 0 6px; }
    .footer .badge { justify-self: start; }
    .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .user { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .main { min-width: 0; }

    @media (max-width: 860px) {
      .layout { grid-template-columns: 1fr; }
      .sidebar { position: sticky; z-index: 10; height: auto; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 8px 12px;
        padding: 10px 12px; border-right: none; border-bottom: 1px solid var(--border); }
      ul { display: flex; overflow-x: auto; order: 3; width: 100%; scrollbar-width: none; }
      li a { white-space: nowrap; }
      li a span { font-size: 0.86rem; }
      .footer { margin: 0 0 0 auto; padding: 0; grid-auto-flow: column; align-items: center; }
      .footer .badge, .footer .subtle { display: none; }
    }
  `,
})
export class Shell {
  readonly product = input.required<string>();
  readonly icon = input('package');
  readonly nav = input.required<NavItem[]>();
  readonly online = input(true);
  protected readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/connexion']);
  }
}
