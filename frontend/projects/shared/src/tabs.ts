import { Signal, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';

/**
 * Onglet d'une page gardé dans l'adresse (?onglet=stockage) : partageable, conservé au rechargement et
 * par le bouton Précédent. Un onglet inconnu ramène au premier.
 */
export function routedTab<T extends string>(
  ids: readonly T[],
): { tab: Signal<T>; select: (id: T) => void } {
  const route = inject(ActivatedRoute);
  const router = inject(Router);
  const params = toSignal(route.queryParamMap, { initialValue: route.snapshot.queryParamMap });
  const tab = computed(() => {
    const value = params().get('onglet') as T | null;
    return value !== null && ids.includes(value) ? value : ids[0];
  });

  return {
    tab,
    select: (id: T) =>
      void router.navigate([], {
        relativeTo: route,
        queryParams: { onglet: id === ids[0] ? null : id },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      }),
  };
}
