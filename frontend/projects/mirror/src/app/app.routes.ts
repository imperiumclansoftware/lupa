import { Routes } from '@angular/router';
import { JobsPage, LoginPage, authGuard } from '@sas/shared';
import { Layout } from './layout';

export const routes: Routes = [
  {
    path: 'connexion',
    component: LoginPage,
    title: 'Connexion · Sas miroir',
    data: { product: 'Miroir hors ligne', icon: 'hdd', command: 'make user app=mirror' },
  },
  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', loadComponent: () => import('./pages/dashboard'), title: 'Tableau de bord · Sas miroir' },
      { path: 'import', loadComponent: () => import('./pages/import'), title: 'Import · Sas miroir' },
      { path: 'paquets', loadComponent: () => import('./pages/packages'), title: 'Paquets · Sas miroir' },
      { path: 'paquets/:ecosystem/:name', loadComponent: () => import('./pages/package'), title: 'Paquet · Sas miroir' },
      { path: 'utilisation', loadComponent: () => import('./pages/usage'), title: 'Utilisation · Sas miroir' },
      { path: 'activite', component: JobsPage, title: 'Activité · Sas miroir', data: { intro: 'Imports de bundles récents, avec leur journal.' } },
      { path: 'reglages', loadComponent: () => import('./pages/settings'), title: 'Réglages · Sas miroir' },
    ],
  },
  { path: '**', redirectTo: '' },
];
