import { Routes } from '@angular/router';
import { JobsPage, LoginPage, SetupPage, authGuard, loginGuard, setupGuard } from '@sas/shared';
import { Layout } from './layout';

export const routes: Routes = [
  {
    path: 'installation',
    component: SetupPage,
    canActivate: [setupGuard],
    title: 'Installation · Romulus',
    data: {
      brand: 'Romulus',
      product: 'Miroir hors ligne',
      logo: 'romulus',
      variant: 'mirror',
      codeCommand:
        "docker compose logs romulus | grep \"Code d'installation\"\n# ou : docker compose exec romulus php bin/console app:setup:code\n# développement : make setup-code app=mirror",
    },
  },
  {
    path: 'connexion',
    component: LoginPage,
    canActivate: [loginGuard],
    title: 'Connexion · Romulus',
    data: { brand: 'Romulus', product: 'Miroir hors ligne', logo: 'romulus', command: 'make user app=mirror' },
  },
  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', loadComponent: () => import('./pages/dashboard'), title: 'Tableau de bord · Romulus' },
      { path: 'import', loadComponent: () => import('./pages/import'), title: 'Import · Romulus' },
      { path: 'paquets', loadComponent: () => import('./pages/packages'), title: 'Paquets · Romulus' },
      { path: 'paquets/:ecosystem/:name', loadComponent: () => import('./pages/package'), title: 'Paquet · Romulus' },
      { path: 'utilisation', loadComponent: () => import('./pages/usage'), title: 'Utilisation · Romulus' },
      { path: 'activite', component: JobsPage, title: 'Activité · Romulus', data: { intro: 'Imports de bundles récents, avec leur journal.' } },
      { path: 'reglages', loadComponent: () => import('./pages/settings'), title: 'Réglages · Romulus' },
    ],
  },
  { path: '**', redirectTo: '' },
];
