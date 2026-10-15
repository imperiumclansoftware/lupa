import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { Icon } from './icon';

export type Severity = 'critical' | 'high' | 'moderate' | 'unknown' | 'low';

export interface Vulnerability {
  id: string;
  aliases: string[];
  summary: string;
  severity: Severity;
  fixed: string[];
  url: string;
}

export interface SecurityCounts {
  critical: number;
  warning: number;
  low: number;
}

export const SEVERITY_LABELS: Record<Severity, string> = {
  critical: 'Critique',
  high: 'Élevée',
  moderate: 'Moyenne',
  unknown: 'Non évaluée',
  low: 'Faible',
};

/** Alerte : « critique » (critical), « avertissement » (high, moderate, non évaluée), information (low). */
export function alertLevel(severity: Severity | null | undefined): 'critical' | 'warning' | 'low' | null {
  if (!severity) return null;
  if (severity === 'critical') return 'critical';
  return severity === 'low' ? 'low' : 'warning';
}

@Component({
  selector: 'sas-severity',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (severity(); as s) {
      <span class="sev" [class]="'sev sev-' + s" [title]="title()">
        <sas-icon [name]="s === 'low' ? 'shield' : 'alert'" [size]="12" />{{ label() }}@if (count() > 1) { <span class="n">· {{ count() }}</span> }
      </span>
    }
  `,
  styles: `
    .sev { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; white-space: nowrap; }
    .sev-critical { background: var(--danger); color: #fff; }
    .sev-high { background: var(--danger-soft); color: var(--danger); }
    .sev-moderate, .sev-unknown { background: var(--warn-soft); color: var(--warn); }
    .sev-low { background: var(--surface-3); color: var(--text-2); }
    .n { font-weight: 600; opacity: 0.85; }
  `,
})
export class SeverityBadge {
  readonly severity = input<Severity | null | undefined>(null);
  readonly count = input(0);
  protected readonly label = computed(() => {
    const s = this.severity();
    return s ? SEVERITY_LABELS[s] : '';
  });
  protected readonly title = computed(() => `Gravité maximale : ${this.label()}${this.count() ? ` (${this.count()} vulnérabilité(s))` : ''}`);
}

/** Résumé « 2 critiques · 3 avertissements » pour une liste ou un tableau de bord. */
@Component({
  selector: 'sas-security-summary',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let c = counts();
    @if (c && (c.critical || c.warning)) {
      @if (c.critical) { <span class="sev-chip critical">{{ c.critical }} critique{{ c.critical > 1 ? 's' : '' }}</span> }
      @if (c.warning) { <span class="sev-chip warning">{{ c.warning }} avertissement{{ c.warning > 1 ? 's' : '' }}</span> }
    } @else if (showOk()) {
      <span class="sev-chip ok">Aucune alerte</span>
    }
  `,
  styles: `
    :host { display: inline-flex; gap: 6px; flex-wrap: wrap; }
    .sev-chip { padding: 2px 9px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; white-space: nowrap; }
    .critical { background: var(--danger); color: #fff; }
    .warning { background: var(--warn-soft); color: var(--warn); }
    .ok { background: var(--ok-soft); color: var(--ok); }
  `,
})
export class SecuritySummary {
  readonly counts = input<SecurityCounts | null | undefined>(null);
  readonly showOk = input(false);
}
