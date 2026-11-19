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

/** Page d'une liste renvoyée par l'API (100 éléments au plus, plafond imposé par le serveur). */
export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  pageSize: number;
}

/**
 * Toutes les pages d'une petite collection (listes, dépôts, clés…) : chaque requête reste bornée
 * côté serveur. Renvoie la première réponse (métadonnées) et l'ensemble des éléments.
 */
export async function allPages<P extends Page<unknown>>(
  load: (page: number) => Promise<P>,
  maxPages = 50,
): Promise<{ first: P; items: P['items'] }> {
  const first = await load(1);
  const items = [...first.items];
  for (let page = 2; items.length < first.total && page <= maxPages; page++) {
    const next = await load(page);
    if (next.items.length === 0) break;
    items.push(...next.items);
  }
  return { first, items };
}
