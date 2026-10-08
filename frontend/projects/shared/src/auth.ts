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

  async load(): Promise<SessionUser | null> {
    if (!this.loaded) {
      const session = await firstValueFrom(this.http.get<{ user: SessionUser | null }>('/api/session'));
      this.user.set(session.user);
      this.loaded = true;
    }
    return this.user();
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
  } catch {
    // Serveur injoignable : l'écran de connexion affichera l'erreur
  }
  return router.createUrlTree(['/connexion'], { queryParams: state.url !== '/' ? { retour: state.url } : {} });
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
