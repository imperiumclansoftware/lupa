import { Routes } from '@angular/router';
import { JobsPage, LoginPage, SetupPage, authGuard, loginGuard, setupGuard } from '@sas/shared';
import { Layout } from './layout';

export const routes: Routes = [
  {
    path: 'installation',
    component: SetupPage,
    canActivate: [setupGuard],
    title: 'Installation · Rémus',
    data: {
      brand: 'Rémus',
      product: 'Collecteur de paquets',
      logo: 'remus',
      variant: 'collector',
      codeCommand:
        "docker compose logs remus | grep \"Code d'installation\"\n# ou : docker compose exec remus php bin/console app:setup:code\n# développement : make setup-code app=collector",
    },
  },
  {
    path: 'connexion',
    component: LoginPage,
    canActivate: [loginGuard],
    title: 'Connexion · Rémus',
    data: { brand: 'Rémus', product: 'Collecteur de paquets', logo: 'remus', command: 'make user app=collector' },
  },
  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', loadComponent: () => import('./pages/dashboard'), title: 'Tableau de bord · Rémus' },
      { path: 'listes', loadComponent: () => import('./pages/lists'), title: 'Listes · Rémus' },
      { path: 'listes/nouvelle', loadComponent: () => import('./pages/list-form'), title: 'Nouvelle liste · Rémus' },
      { path: 'listes/:id', loadComponent: () => import('./pages/list-detail'), title: 'Liste · Rémus' },
      { path: 'listes/:id/modifier', loadComponent: () => import('./pages/list-form'), title: 'Modifier la liste · Rémus' },
      { path: 'paquets', loadComponent: () => import('./pages/artifacts'), title: 'Paquets · Rémus' },
      { path: 'exports', loadComponent: () => import('./pages/exports'), title: 'Exports · Rémus' },
      { path: 'activite', component: JobsPage, title: 'Activité · Rémus', data: { intro: 'Résolutions de listes et exports récents, avec leur journal.' } },
      { path: 'reglages', loadComponent: () => import('./pages/settings'), title: 'Réglages · Rémus' },
    ],
  },
  { path: '**', redirectTo: '' },
];
