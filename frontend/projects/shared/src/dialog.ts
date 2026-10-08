import { ChangeDetectionStrategy, Component, ElementRef, input, output, viewChild } from '@angular/core';
import { Icon } from './icon';

/** Boîte de dialogue modale native (<dialog> : focus piégé, Échap, fond inerte). */
@Component({
  selector: 'sas-dialog',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dialog #dialog class="sas-dialog" [attr.aria-labelledby]="id + '-title'" (close)="closed.emit()" (click)="backdrop($event)">
      <header>
        <h2 [id]="id + '-title'">{{ heading() }}</h2>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" (click)="close()" aria-label="Fermer">
          <sas-icon name="x" />
        </button>
      </header>
      <div class="content"><ng-content /></div>
      <footer><ng-content select="[dialog-actions]" /></footer>
    </dialog>
  `,
  styles: `
    header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 20px 0; }
    .content { padding: 16px 20px; display: grid; gap: 14px; }
    footer { display: flex; justify-content: flex-end; gap: 8px; padding: 0 20px 18px; }
    footer:empty { display: none; }
  `,
})
export class Dialog {
  private static counter = 0;
  protected readonly id = `sas-dialog-${++Dialog.counter}`;
  readonly heading = input.required<string>();
  readonly closed = output<void>();
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  open(): void {
    if (!this.dialog().nativeElement.open) this.dialog().nativeElement.showModal();
  }

  close(): void {
    this.dialog().nativeElement.close();
  }

  /** Clic sur le fond (en dehors du cadre) : fermeture. */
  protected backdrop(event: MouseEvent): void {
    if (event.target === this.dialog().nativeElement) this.close();
  }
}
