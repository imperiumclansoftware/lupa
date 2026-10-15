import type { EcosystemId } from '@sas/shared';

/**
 * Lignes du champ « Paquets » d'une liste : comparaison par paquet (et non par texte exact),
 * pour combiner des listes prédéfinies sans créer de doublons.
 */

/** Lignes utiles (sans vide ni commentaire), telles que saisies. */
export function specLines(text: string): string[] {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('#'));
}

/**
 * Clé d'identité d'une ligne :
 *  - Python : nom normalisé (PEP 503), sans version ni extras : « Django>=5 » et « django » → « django » ;
 *  - Docker : référence complète normalisée : « nginx » et « docker.io/library/nginx:latest » → « nginx:latest » ;
 *  - npm, Composer : nom du paquet, en minuscules.
 */
export function lineKey(line: string, ecosystem: EcosystemId): string {
  const value = line.trim().replace(/\s+#.*$/, '');
  switch (ecosystem) {
    case 'pypi':
      return value.split(/[\s<>=!~;[(@,]/)[0].toLowerCase().replace(/[-_.]+/g, '-');
    case 'docker': {
      let ref = value.toLowerCase().replace(/^docker\.io\//, '').replace(/^index\.docker\.io\//, '');
      ref = ref.replace(/^library\//, '');
      const last = ref.split('/').pop() ?? ref;
      if (!last.includes(':') && !ref.includes('@')) ref += ':latest';
      return ref;
    }
    case 'npm': {
      const at = value.lastIndexOf('@');
      return (at > 0 ? value.slice(0, at) : value).toLowerCase();
    }
    case 'composer':
      return value.split(/[\s:]/)[0].toLowerCase();
  }
}

/** Ajoute les lignes dont le paquet n'est pas déjà présent ; le texte existant est conservé tel quel. */
export function addLines(text: string, lines: string[], ecosystem: EcosystemId): string {
  const present = new Set(specLines(text).map((l) => lineKey(l, ecosystem)));
  const added: string[] = [];
  for (const line of lines) {
    const key = lineKey(line, ecosystem);
    if (!present.has(key)) {
      present.add(key);
      added.push(line);
    }
  }
  if (added.length === 0) return text;
  const base = text.replace(/\s+$/, '');
  return (base ? base + '\n' : '') + added.join('\n');
}

/** Retire les lignes de ces paquets, sauf ceux de $keep ; commentaires et autres lignes conservés. */
export function removeLines(text: string, lines: string[], ecosystem: EcosystemId, keep: Set<string> = new Set()): string {
  const remove = new Set(lines.map((l) => lineKey(l, ecosystem)).filter((k) => !keep.has(k)));
  return text
    .split(/\r?\n/)
    .filter((line) => {
      const trimmed = line.trim();
      return trimmed === '' || trimmed.startsWith('#') || !remove.has(lineKey(trimmed, ecosystem));
    })
    .join('\n')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/^\n+/, '');
}

/** Tous les paquets de $lines sont présents dans le texte. */
export function containsAll(text: string, lines: string[], ecosystem: EcosystemId): boolean {
  const present = new Set(specLines(text).map((l) => lineKey(l, ecosystem)));
  return lines.length > 0 && lines.every((l) => present.has(lineKey(l, ecosystem)));
}

/** Paquets présents plusieurs fois (leurs clés). */
export function duplicates(text: string, ecosystem: EcosystemId): string[] {
  const counts = new Map<string, number>();
  for (const line of specLines(text)) {
    const key = lineKey(line, ecosystem);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return [...counts].filter(([, n]) => n > 1).map(([key]) => key);
}

/** Retire les doublons en gardant la première occurrence de chaque paquet. */
export function removeDuplicates(text: string, ecosystem: EcosystemId): string {
  const seen = new Set<string>();
  return text
    .split(/\r?\n/)
    .filter((line) => {
      const trimmed = line.trim();
      if (trimmed === '' || trimmed.startsWith('#')) return true;
      const key = lineKey(trimmed, ecosystem);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    })
    .join('\n');
}
