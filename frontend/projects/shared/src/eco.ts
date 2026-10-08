import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { Icon } from './icon';

/** Pastille d'écosystème : icône dans un carré teinté de la couleur de l'écosystème. */
@Component({
  selector: 'sas-eco',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[style.--eco]': "'var(--eco-' + ecosystem() + ')'", '[style.--size.px]': 'size()' },
  template: `<sas-icon [name]="'eco-' + ecosystem()" [size]="size() * 0.55" />`,
  styles: `
    :host { display: inline-grid; place-items: center; flex: none; width: var(--size); height: var(--size);
      border-radius: calc(var(--size) * 0.3); color: var(--eco);
      background: color-mix(in oklab, var(--eco) 13%, transparent); }
  `,
})
export class Eco {
  readonly ecosystem = input.required<string>();
  readonly size = input(32);
}
