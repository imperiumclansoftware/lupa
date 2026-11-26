// Écosystèmes du front = enum PHP Sas\Bundle\Ecosystem : node --test projects/shared/tests/ecosystems.test.ts
// L'enum est hors de frontend/ : présent en CI (dépôt complet), absent du conteneur Node de développement.
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';

const enumFile = new URL('../../../../shared/src/Ecosystem.php', import.meta.url);
const formatFile = new URL('../src/format.ts', import.meta.url);

test('EcosystemId reprend exactement les cas de l’enum PHP', (t) => {
  if (!existsSync(enumFile)) {
    if (process.env['CI']) assert.fail(`enum introuvable : ${enumFile.pathname}`);
    t.skip('enum PHP hors du conteneur : vérifié en CI');
    return;
  }
  const php = [...readFileSync(enumFile, 'utf8').matchAll(/^\s*case \w+ = '([a-z0-9-]+)';/gm)].map(
    (m) => m[1],
  );
  const union =
    /export type EcosystemId =([^;]+);/.exec(readFileSync(formatFile, 'utf8'))?.[1] ?? '';
  const ts = [...union.matchAll(/'([a-z0-9-]+)'/g)].map((m) => m[1]);
  assert.ok(php.length >= 9, 'cas de l’enum non lus');
  assert.deepEqual([...ts].sort(), [...php].sort());
});
