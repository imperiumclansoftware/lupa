import { HttpClient } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  Injectable,
  InjectionToken,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  viewChild,
  Injector,
} from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { filter, firstValueFrom } from 'rxjs';
import { Auth } from './auth';
import { Icon } from './icon';

/** Étape d'une visite : bulle sur un élément marqué data-tour="…", ou au centre de l'écran. */
export interface TourStep {
  /** Valeur de l'attribut data-tour de l'élément mis en avant ; absent : bulle centrée */
  target?: string;
  title: string;
  text: string;
}

export interface Tour {
  /** Identifiant retenu sur le compte (minuscules, chiffres, tirets) */
  id: string;
  /** Pages concernées : chemin de l'adresse, sans paramètres (ex. /^\/listes\/[^/]+$/) */
  path: RegExp;
  steps: TourStep[];
}

/** Visites guidées de l'application (Rémus ou Romulus), fournies par app.config. */
export const TOURS = new InjectionToken<Tour[]>('Visites guidées');

/**
 * Visites guidées : ouverte d'elle-même à la première visite d'une page (une fois par compte, retenu par le
 * serveur), puis à la demande par le bouton Aide de la navigation.
 */
@Injectable({ providedIn: 'root' })
export class Tours {
  private readonly tours = inject(TOURS, { optional: true }) ?? [];
  private readonly router = inject(Router);
  private readonly http = inject(HttpClient);
  private readonly auth = inject(Auth);
  private readonly url = signal(this.path(this.router.url));
  /** Visite de la page courante, s'il y en a une */
  readonly current = computed(() => this.tours.find((t) => t.path.test(this.url())) ?? null);
  /** Visite affichée et étape */
  readonly active = signal<{ tour: Tour; steps: TourStep[]; index: number } | null>(null);
  private attempt = 0;

  constructor() {
    this.router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => {
      this.url.set(this.path(this.router.url));
      this.close(false);
      const tour = this.current();
      const seen = this.auth.user()?.seenTours ?? [];
      if (tour && !seen.includes(tour.id)) {
        void this.start(tour, true);
      }
    });
  }

  /** Bouton Aide : la visite de la page courante, même déjà vue. */
  help(): void {
    const tour = this.current();
    if (tour) void this.start(tour, false);
  }

  next(): void {
    const a = this.active();
    if (!a) return;
    if (a.index + 1 < a.steps.length) this.active.set({ ...a, index: a.index + 1 });
    else this.close(true);
  }

  previous(): void {
    const a = this.active();
    if (a && a.index > 0) this.active.set({ ...a, index: a.index - 1 });
  }

  /** Fermeture (terminée ou passée) : retenue sur le compte, la visite ne s'ouvrira plus d'elle-même. */
  close(remember = true): void {
    const a = this.active();
    this.active.set(null);
    ++this.attempt;
    if (!a || !remember) return;
    const user = this.auth.user();
    if (user && !user.seenTours.includes(a.tour.id)) {
      this.auth.user.set({ ...user, seenTours: [...user.seenTours, a.tour.id] });
      void firstValueFrom(this.http.post('/api/account/tours', { tour: a.tour.id })).catch(
        () => undefined,
      );
    }
  }

  /** La page charge souvent ses données après la navigation : attend ses éléments (4 s au plus). */
  private async start(tour: Tour, automatic: boolean): Promise<void> {
    const attempt = ++this.attempt;
    const targets = tour.steps.map((s) => s.target).filter((t): t is string => !!t);
    for (let waited = 0; waited < 4000; waited += 150) {
      if (targets.length === 0 || targets.some((t) => Tours.element(t))) break;
      await new Promise((resolve) => setTimeout(resolve, 150));
      if (attempt !== this.attempt) return;
    }
    if (automatic) await new Promise((resolve) => setTimeout(resolve, 400));
    if (attempt !== this.attempt) return;
    // Étapes dont l'élément n'est pas affiché (droits, liste vide…) : passées
    const steps = tour.steps.filter((s) => !s.target || Tours.element(s.target));
    if (steps.length > 0) this.active.set({ tour, steps, index: 0 });
  }

  static element(target: string): HTMLElement | null {
    const element = document.querySelector<HTMLElement>(`[data-tour="${CSS.escape(target)}"]`);
    return element && element.getClientRects().length > 0 ? element : null;
  }

  private path(url: string): string {
    return url.split(/[?#]/)[0];
  }
}

/** Affichage de la visite : découpe lumineuse autour de l'élément et bulle positionnée à côté. */
@Component({
  selector: 'sas-tour',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (tours.active(); as a) {
      @let step = a.steps[a.index];
      <div class="blocker" aria-hidden="true"></div>
      @if (spot(); as r) {
        <div
          class="spot"
          aria-hidden="true"
          [style.top.px]="r.top"
          [style.left.px]="r.left"
          [style.width.px]="r.width"
          [style.height.px]="r.height"
        ></div>
      } @else {
        <div class="dim" aria-hidden="true"></div>
      }
      <div
        #pop
        class="pop"
        [class.centered]="!spot()"
        role="dialog"
        aria-modal="true"
        aria-labelledby="tour-title"
        aria-describedby="tour-text"
        [style.top.px]="position()?.top"
        [style.left.px]="position()?.left"
        (keydown)="key($event)"
      >
        <div class="head">
          <span class="badge">
            <sas-icon name="help" [size]="14" />Étape {{ a.index + 1 }} sur {{ a.steps.length }}
          </span>
          <button
            type="button"
            class="btn btn-ghost btn-icon btn-sm"
            (click)="tours.close()"
            aria-label="Fermer la visite guidée"
          >
            <sas-icon name="x" [size]="16" />
          </button>
        </div>
        <h2 id="tour-title">{{ step.title }}</h2>
        <p id="tour-text">{{ step.text }}</p>
        <div class="dots" aria-hidden="true">
          @for (s of a.steps; track $index) {
            <span [class.on]="$index === a.index"></span>
          }
        </div>
        <div class="actions">
          <button type="button" class="btn btn-ghost btn-sm" (click)="tours.close()">Passer</button>
          <span class="spacer"></span>
          @if (a.index > 0) {
            <button type="button" class="btn btn-sm" (click)="tours.previous()">Précédent</button>
          }
          <button type="button" class="btn btn-primary btn-sm next" (click)="tours.next()">
            {{ a.index + 1 < a.steps.length ? 'Suivant' : 'Terminer' }}
          </button>
        </div>
      </div>
    }
  `,
  styles: `
    .blocker,
    .dim {
      position: fixed;
      inset: 0;
      z-index: 1000;
    }
    .dim {
      background: rgb(9 12 20 / 0.55);
      pointer-events: none;
    }
    .spot {
      position: fixed;
      z-index: 1001;
      border-radius: var(--radius-s, 10px);
      box-shadow:
        0 0 0 3px var(--accent, #3b82f6),
        0 0 0 100vmax rgb(9 12 20 / 0.55);
      pointer-events: none;
      transition:
        top 0.25s var(--ease, ease),
        left 0.25s var(--ease, ease),
        width 0.25s var(--ease, ease),
        height 0.25s var(--ease, ease);
    }
    .pop {
      position: fixed;
      z-index: 1002;
      width: min(380px, calc(100vw - 24px));
      padding: 16px 18px 14px;
      border-radius: var(--radius-l, 16px);
      background: var(--surface);
      color: var(--text);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-l);
      animation: pop-in 0.18s var(--ease, ease);
    }
    .pop.centered {
      top: 50% !important;
      left: 50% !important;
      transform: translate(-50%, -50%);
    }
    .head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    h2 {
      margin: 4px 0 6px;
      font-size: 17px;
    }
    p {
      margin: 0;
      color: var(--text-2);
      line-height: 1.55;
    }
    .dots {
      display: flex;
      gap: 5px;
      margin: 14px 0 12px;
    }
    .dots span {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--border-strong);
    }
    .dots span.on {
      width: 18px;
      border-radius: 3px;
      background: var(--accent, var(--text));
    }
    .actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .spacer {
      flex: 1;
    }
    @keyframes pop-in {
      from {
        opacity: 0;
        translate: 0 6px;
      }
    }
    @media (prefers-reduced-motion: reduce) {
      .spot,
      .pop {
        transition: none;
        animation: none;
      }
    }
  `,
})
export class TourOverlay {
  protected readonly tours = inject(Tours);
  private readonly injector = inject(Injector);
  private readonly pop = viewChild<ElementRef<HTMLElement>>('pop');
  /** Rectangle mis en avant (marge comprise), null pour une étape centrée */
  protected readonly spot = signal<{
    top: number;
    left: number;
    width: number;
    height: number;
  } | null>(null);
  protected readonly position = signal<{ top: number; left: number } | null>(null);
  private returnFocus: HTMLElement | null = null;

  constructor() {
    const reposition = () => this.place();
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
    inject(DestroyRef).onDestroy(() => {
      window.removeEventListener('resize', reposition);
      window.removeEventListener('scroll', reposition, true);
    });

    effect(() => {
      const a = this.tours.active();
      if (!a) {
        this.spot.set(null);
        this.returnFocus?.focus();
        this.returnFocus = null;
        return;
      }
      this.returnFocus ??= document.activeElement as HTMLElement | null;
      const target = a.steps[a.index].target;
      const element = target ? Tours.element(target) : null;
      const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      element?.scrollIntoView({ block: 'center', behavior: smooth ? 'smooth' : 'auto' });
      afterNextRender(
        () => {
          this.place();
          // Après le défilement doux, la position se stabilise
          setTimeout(() => this.place(), smooth ? 350 : 0);
          this.pop()?.nativeElement.querySelector<HTMLElement>('.next')?.focus();
        },
        { injector: this.injector },
      );
    });
  }

  protected key(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.tours.close();
    } else if (event.key === 'ArrowRight') {
      this.tours.next();
    } else if (event.key === 'ArrowLeft') {
      this.tours.previous();
    } else if (event.key === 'Tab') {
      // Le focus reste dans la bulle
      const items = [...(this.pop()?.nativeElement.querySelectorAll<HTMLElement>('button') ?? [])];
      const index = items.indexOf(document.activeElement as HTMLElement);
      const next = event.shiftKey ? index - 1 : index + 1;
      if (next < 0 || next >= items.length) {
        event.preventDefault();
        items[next < 0 ? items.length - 1 : 0]?.focus();
      }
    }
  }

  /** Bulle sous l'élément, sinon au-dessus, sinon à côté ; toujours dans l'écran. */
  private place(): void {
    const a = this.tours.active();
    const target = a?.steps[a.index].target;
    const element = target ? Tours.element(target) : null;
    if (!a || !element) {
      this.spot.set(null);
      this.position.set(null);
      return;
    }
    const margin = 6;
    const r = element.getBoundingClientRect();
    this.spot.set({
      top: r.top - margin,
      left: r.left - margin,
      width: r.width + 2 * margin,
      height: r.height + 2 * margin,
    });
    const pop = this.pop()?.nativeElement.getBoundingClientRect();
    const w = pop?.width ?? 380;
    const h = pop?.height ?? 200;
    const gap = 14;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let top: number;
    let left = r.left + r.width / 2 - w / 2;
    if (r.bottom + gap + h <= vh) top = r.bottom + gap;
    else if (r.top - gap - h >= 0) top = r.top - gap - h;
    else {
      // Élément haut (barre de navigation) : à sa droite, sinon à sa gauche
      top = Math.min(Math.max(r.top, 12), vh - h - 12);
      left = r.right + gap + w <= vw ? r.right + gap : r.left - gap - w;
    }
    this.position.set({
      top: Math.max(12, Math.min(top, vh - h - 12)),
      left: Math.max(12, Math.min(left, vw - w - 12)),
    });
  }
}
