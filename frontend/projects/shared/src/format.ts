import { Pipe, PipeTransform } from '@angular/core';

export type EcosystemId = 'npm' | 'pypi' | 'composer' | 'docker';

export const ECOSYSTEMS: { id: EcosystemId; label: string; noun: string }[] = [
  { id: 'npm', label: 'npm', noun: 'paquets npm' },
  { id: 'pypi', label: 'Python', noun: 'fichiers Python' },
  { id: 'composer', label: 'Composer', noun: 'paquets Composer' },
  { id: 'docker', label: 'Docker', noun: 'images Docker' },
];

export function ecosystemLabel(id: string): string {
  return ECOSYSTEMS.find((e) => e.id === id)?.label ?? id;
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return '—';
  const units: [string, number][] = [['To', 2 ** 40], ['Go', 2 ** 30], ['Mo', 2 ** 20], ['ko', 2 ** 10]];
  for (const [unit, size] of units) {
    if (bytes >= size) {
      const value = bytes / size;
      return `${value.toLocaleString('fr-FR', { maximumFractionDigits: value < 10 ? 1 : 0 })} ${unit}`;
    }
  }
  return `${bytes} o`;
}

const relative = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' });

export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return '—';
  const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
  const abs = Math.abs(seconds);
  if (abs < 45) return 'à l’instant';
  if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute');
  if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour');
  if (abs < 86400 * 30) return relative.format(Math.round(seconds / 86400), 'day');
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
}

@Pipe({ name: 'bytes' })
export class BytesPipe implements PipeTransform {
  transform(value: number | null | undefined): string {
    return formatBytes(value);
  }
}

@Pipe({ name: 'relative' })
export class RelativePipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    return formatRelative(value);
  }
}

@Pipe({ name: 'datetime' })
export class DateTimePipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    return formatDateTime(value);
  }
}

@Pipe({ name: 'ecosystem' })
export class EcosystemPipe implements PipeTransform {
  transform(value: string): string {
    return ecosystemLabel(value);
  }
}

/** Accord simple : plural(3, 'paquet') → « 3 paquets ». */
@Pipe({ name: 'plural' })
export class PluralPipe implements PipeTransform {
  transform(count: number | null | undefined, singular: string, plural = singular + 's'): string {
    const n = count ?? 0;
    return `${n.toLocaleString('fr-FR')} ${n > 1 ? plural : singular}`;
  }
}
