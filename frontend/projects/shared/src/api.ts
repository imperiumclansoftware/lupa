import { HttpErrorResponse } from '@angular/common/http';

/** Message lisible d'une erreur HTTP : l'API renvoie {"error": "…"}. */
export function errorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    const body = error.error as { error?: string; errors?: { message: string }[] } | string | null;
    if (body && typeof body === 'object' && typeof body.error === 'string') return body.error;
    if (error.status === 0) return 'Serveur injoignable.';
    if (error.status === 401) return 'Session expirée : reconnectez-vous.';
    return `Erreur ${error.status}.`;
  }
  return error instanceof Error ? error.message : 'Erreur inattendue.';
}
