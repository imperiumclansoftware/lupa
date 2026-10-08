import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { Icon } from './icon';

@Component({
  selector: 'sas-copy',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button type="button" class="btn btn-sm" [class.btn-icon]="!label()" (click)="copy()"
      [attr.aria-label]="label() ? null : 'Copier'" [title]="copied() ? 'Copié' : 'Copier'">
      <sas-icon [name]="copied() ? 'check' : 'copy'" [size]="15" />
      @if (label()) { <span>{{ copied() ? 'Copié' : label() }}</span> }
    </button>
  `,
})
export class CopyButton {
  readonly text = input.required<string>();
  readonly label = input<string>('');
  protected readonly copied = signal(false);

  protected async copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.text());
    } catch {
      // HTTP hors localhost : l'API presse-papiers est indisponible, repli sur une zone de texte temporaire
      const area = document.createElement('textarea');
      area.value = this.text();
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.append(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
    this.copied.set(true);
    setTimeout(() => this.copied.set(false), 1600);
  }
}
