import { ChangeDetectionStrategy, Component, Injectable, inject, signal } from '@angular/core';
import { Icon } from './icon';

export interface Toast {
  id: number;
  kind: 'ok' | 'error' | 'info';
  text: string;
}

@Injectable({ providedIn: 'root' })
export class Toasts {
  private next = 1;
  readonly items = signal<Toast[]>([]);

  ok(text: string): void {
    this.push('ok', text);
  }

  error(text: string): void {
    this.push('error', text, 8000);
  }

  info(text: string): void {
    this.push('info', text);
  }

  dismiss(id: number): void {
    this.items.update((items) => items.filter((t) => t.id !== id));
  }

  private push(kind: Toast['kind'], text: string, duration = 4500): void {
    const id = this.next++;
    this.items.update((items) => [...items.slice(-3), { id, kind, text }]);
    setTimeout(() => this.dismiss(id), duration);
  }
}

@Component({
  selector: 'sas-toasts',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="toasts" role="status" aria-live="polite">
      @for (toast of toasts.items(); track toast.id) {
        <div class="toast" [class]="'toast toast-' + toast.kind">
          <sas-icon [name]="toast.kind === 'error' ? 'alert' : 'check'" />
          <span>{{ toast.text }}</span>
          <button type="button" class="btn btn-ghost btn-sm btn-icon" (click)="toasts.dismiss(toast.id)" aria-label="Fermer">
            <sas-icon name="x" [size]="15" />
          </button>
        </div>
      }
    </div>
  `,
  styles: `
    .toasts { position: fixed; right: 20px; bottom: 20px; z-index: 50; display: grid; gap: 8px; width: min(420px, calc(100vw - 40px)); }
    .toast { display: flex; align-items: center; gap: 10px; padding: 10px 10px 10px 14px; border-radius: var(--radius);
      background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-l); font-size: 0.9rem;
      white-space: pre-line; animation: sas-pop 160ms ease-out; }
    .toast span { flex: 1; }
    .toast-ok sas-icon { color: var(--ok); }
    .toast-error { border-color: var(--danger); }
    .toast-error sas-icon:first-child { color: var(--danger); }
  `,
})
export class ToastHost {
  protected readonly toasts = inject(Toasts);
}
