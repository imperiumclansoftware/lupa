import { HttpClient, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, firstValueFrom, throwError } from 'rxjs';

export interface SessionUser {
  username: string;
}

@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly http = inject(HttpClient);
  private loaded = false;
  readonly user = signal<SessionUser | null>(null);
  /** Aucun compte n'existe encore : l'assistant d'installation doit être utilisé */
  readonly setupRequired = signal(false);

  async load(force = false): Promise<SessionUser | null> {
    if (!this.loaded || force) {
      const session = await firstValueFrom(this.http.get<{ user: SessionUser | null; setupRequired?: boolean }>('/api/session'));
      this.user.set(session.user);
      this.setupRequired.set(!!session.setupRequired);
      this.loaded = true;
    }
    return this.user();
  }

  /** Crée le premier compte (assistant d'installation) ; la session est ouverte dans la foulée. */
  async setup(payload: Record<string, string | null>): Promise<void> {
    const result = await firstValueFrom(this.http.post<{ user: SessionUser }>('/api/setup', payload));
    this.user.set(result.user);
    this.setupRequired.set(false);
    this.loaded = true;
  }

  async login(username: string, password: string): Promise<void> {
    const user = await firstValueFrom(this.http.post<SessionUser>('/api/login', { username, password }));
    this.user.set(user);
    this.loaded = true;
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
  return router.createUrlTree(['/connexion'], { queryParams: state.url !== '/' ? { retour: state.url } : {} });
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
      if (error instanceof HttpErrorResponse && error.status === 401 && !req.url.endsWith('/api/login') && auth.user()) {
        auth.expire();
        void router.navigate(['/connexion'], { queryParams: { retour: router.url } });
      }
      return throwError(() => error);
    }),
  );
};
