import { Routes } from '@angular/router';
import { JobsPage, LoginPage, authGuard } from '@sas/shared';
import { Layout } from './layout';

export const routes: Routes = [
  {
    path: 'connexion',
    component: LoginPage,
    title: 'Connexion · Sas collecteur',
    data: { product: 'Collecteur', icon: 'globe', command: 'make user app=collector' },
  },
  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', loadComponent: () => import('./pages/dashboard'), title: 'Tableau de bord · Sas collecteur' },
      { path: 'listes', loadComponent: () => import('./pages/lists'), title: 'Listes · Sas collecteur' },
      { path: 'listes/nouvelle', loadComponent: () => import('./pages/list-form'), title: 'Nouvelle liste · Sas collecteur' },
      { path: 'listes/:id', loadComponent: () => import('./pages/list-detail'), title: 'Liste · Sas collecteur' },
      { path: 'listes/:id/modifier', loadComponent: () => import('./pages/list-form'), title: 'Modifier la liste · Sas collecteur' },
      { path: 'paquets', loadComponent: () => import('./pages/artifacts'), title: 'Paquets · Sas collecteur' },
      { path: 'exports', loadComponent: () => import('./pages/exports'), title: 'Exports · Sas collecteur' },
      { path: 'activite', component: JobsPage, title: 'Activité · Sas collecteur', data: { intro: 'Résolutions de listes et exports récents, avec leur journal.' } },
      { path: 'reglages', loadComponent: () => import('./pages/settings'), title: 'Réglages · Sas collecteur' },
    ],
  },
  { path: '**', redirectTo: '' },
];
