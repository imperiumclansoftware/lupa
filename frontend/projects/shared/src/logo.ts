import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export type LogoName = 'remus' | 'romulus' | 'lupa';

/**
 * Marques de Lupa, dessinées sur une grille de 48 px (trait, sans dépendance externe) :
 *  - Rémus, le collecteur : une trajectoire qui franchit le mur de la cité ;
 *  - Romulus, le miroir : la porte fortifiée qui garde l'enceinte ;
 *  - Lupa, l'ensemble : la louve qui veille sur les deux.
 */
const MARKS: Record<LogoName, { title: string; paths: string[] }> = {
  remus: {
    title: 'Rémus',
    paths: [
      // Mur crénelé
      'M19 40V28h10v12M19 28v-3h3v3M26 28v-3h3v3',
      // Saut par-dessus le mur
      'M8 35Q24 -11 40 35',
      'M40 35l1.1-5.9M40 35l-4.5-3.9',
      'M6 40h36',
    ],
  },
  romulus: {
    title: 'Romulus',
    paths: [
      // Enceinte crénelée et porte en plein cintre
      'M10 40V20h28v20',
      'M10 20v-5h5v5M21.5 20v-5h5v5M33 20v-5h5v5',
      'M19 40v-9a5 5 0 0 1 10 0v9',
      'M6 40h36',
    ],
  },
  lupa: {
    title: 'Lupa',
    paths: [
      // Tête de louve : oreilles, joues, museau
      'M11 9l6 9h14l6-9 1 15-8 11-6 4-6-4-8-11z',
      'M18 25l3 1.5M30 25l-3 1.5',
      'M22 33h4l-2 2z',
    ],
  },
};

@Component({
  selector: 'sas-logo',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg [attr.width]="size()" [attr.height]="size()" viewBox="0 0 48 48" role="img" [attr.aria-label]="mark().title">
      @if (!bare()) { <rect width="48" height="48" rx="12" class="tile" /> }
      @for (d of mark().paths; track $index) {
        <path [attr.d]="d" fill="none" [class.on-tile]="!bare()" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" />
      }
    </svg>
  `,
  styles: `
    :host { display: inline-flex; flex: none; }
    svg { display: block; }
    .tile { fill: var(--accent); }
    path { stroke: currentColor; }
    path.on-tile { stroke: var(--on-accent, #fff); }
  `,
})
export class Logo {
  readonly name = input.required<LogoName>();
  readonly size = input(36);
  /** Sans la tuile de couleur (marque seule, couleur du texte) */
  readonly bare = input(false);
  protected readonly mark = computed(() => MARKS[this.name()]);
}
