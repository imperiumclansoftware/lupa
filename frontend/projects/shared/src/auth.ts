import { HttpClient, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, firstValueFrom, throwError } from 'rxjs';

/** Niveaux de droits (ADR 0010) ; l'API les vérifie, le front s'en sert seulement pour l'affichage. */
export type Role = 'admin' | 'operator' | 'reader';

const RANK: Record<Role, number> = { reader: 0, operator: 1, admin: 2 };

export interface SessionUser {
  id: string;
  username: string;
  role: Role;
  roleLabel: string;
  twoFactor: boolean;
  backupCodes: number;
  /** Visites guidées déjà vues (identifiants) */
  seenTours: string[];
}

interface Session {
  user: SessionUser | null;
  twoFactor?: boolean;
  setupRequired?: boolean;
}

@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly http = inject(HttpClient);
  private loaded = false;
  readonly user = signal<SessionUser | null>(null);
  /** Aucun compte n'existe encore : l'assistant d'installation doit être utilisé */
  readonly setupRequired = signal(false);
  /** Mot de passe accepté, code de double authentification attendu */
  readonly twoFactorPending = signal(false);

  async load(force = false): Promise<SessionUser | null> {
    if (!this.loaded || force) {
      const session = await firstValueFrom(this.http.get<Session>('/api/session'));
      this.user.set(session.user);
      this.twoFactorPending.set(!!session.twoFactor);
      this.setupRequired.set(!!session.setupRequired);
      this.loaded = true;
    }
    return this.user();
  }

  /** Le compte connecté a-t-il au moins ce rôle ? (affichage seulement : l'API décide) */
  can(role: Role): boolean {
    const user = this.user();
    return !!user && RANK[user.role] >= RANK[role];
  }

  /** Crée le premier compte (assistant d'installation) ; la session est ouverte dans la foulée. */
  async setup(payload: Record<string, string | null>): Promise<void> {
    const result = await firstValueFrom(
      this.http.post<{ user: SessionUser }>('/api/setup', payload),
    );
    this.user.set(result.user);
    this.setupRequired.set(false);
    this.loaded = true;
  }

  /** @returns true si un code de double authentification est maintenant attendu */
  async login(username: string, password: string): Promise<boolean> {
    const result = await firstValueFrom(
      this.http.post<{ twoFactor?: boolean }>('/api/login', { username, password }),
    );
    if (result.twoFactor) {
      this.twoFactorPending.set(true);
      return true;
    }
    await this.load(true);
    return false;
  }

  /** Code TOTP ou code de secours ; après cinq erreurs, le serveur ferme la session. */
  async verifyCode(code: string): Promise<void> {
    try {
      await firstValueFrom(this.http.post('/api/2fa/check', { code }));
    } catch (e) {
      if (e instanceof HttpErrorResponse && e.error?.twoFactor === false) {
        this.twoFactorPending.set(false);
      }
      throw e;
    }
    await this.load(true);
  }

  /** Mise à jour après une modification de son compte (mot de passe, double authentification) */
  update(user: SessionUser): void {
    this.user.set(user);
  }

  async logout(): Promise<void> {
    try {
      await firstValueFrom(this.http.post('/api/logout', {}, { responseType: 'text' }));
    } finally {
      this.expire();
    }
  }

  expire(): void {
    this.user.set(null);
    this.twoFactorPending.set(false);
    this.loaded = true;
  }
}

/** Pages protégées : renvoie vers /connexion si la session est absente. */
export const authGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(Auth);
  const router = inject(Router);
  try {
    if (await auth.load()) return true;
    if (auth.setupRequired()) return router.createUrlTree(['/installation']);
  } catch {
    // Serveur injoignable : l'écran de connexion affichera l'erreur
  }
  return router.createUrlTree(['/connexion'], {
    queryParams: state.url !== '/' ? { retour: state.url } : {},
  });
};

/** Pages réservées à un rôle (ergonomie : l'API refuse de toute façon). */
export const roleGuard =
  (role: Role): CanActivateFn =>
  () => {
    const auth = inject(Auth);
    return auth.can(role) ? true : inject(Router).createUrlTree(['/']);
  };

/** Assistant d'installation : seulement tant qu'aucun compte n'existe. */
export const setupGuard: CanActivateFn = async () => {
  const auth = inject(Auth);
  const router = inject(Router);
  try {
    await auth.load(true);
  } catch {
    return true;
  }
  if (auth.user()) return router.createUrlTree(['/']);
  return auth.setupRequired() ? true : router.createUrlTree(['/connexion']);
};

/** Écran de connexion : redirige vers l'assistant si aucun compte n'existe. */
export const loginGuard: CanActivateFn = async () => {
  const auth = inject(Auth);
  const router = inject(Router);
  try {
    await auth.load(true);
  } catch {
    return true;
  }
  if (auth.user()) return router.createUrlTree(['/']);
  return auth.setupRequired() ? router.createUrlTree(['/installation']) : true;
};

/** Session expirée pendant l'utilisation : retour à l'écran de connexion. */
export const sessionInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(Auth);
  const router = inject(Router);
  return next(req).pipe(
    catchError((error: unknown) => {
      if (
        error instanceof HttpErrorResponse &&
        error.status === 401 &&
        !req.url.endsWith('/api/login') &&
        !req.url.endsWith('/api/2fa/check') &&
        auth.user()
      ) {
        auth.expire();
        void router.navigate(['/connexion'], { queryParams: { retour: router.url } });
      }
      return throwError(() => error);
    }),
  );
};
