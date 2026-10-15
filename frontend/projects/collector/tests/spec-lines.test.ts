// Tests de la combinaison des listes prédéfinies : node --test projects/collector/tests/spec-lines.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addLines, containsAll, duplicates, lineKey, removeDuplicates, removeLines } from '../src/app/spec-lines.ts';

const django = ['django', 'psycopg[binary]', 'gunicorn', 'python-dotenv'];
const flask = ['flask', 'gunicorn', 'python-dotenv'];

test('clés : même paquet quelle que soit l’écriture', () => {
  assert.equal(lineKey('Django>=5,<6', 'pypi'), 'django');
  assert.equal(lineKey('python_dotenv', 'pypi'), lineKey('python-dotenv', 'pypi'));
  assert.equal(lineKey('psycopg[binary]==3.2', 'pypi'), 'psycopg');
  assert.equal(lineKey('nginx', 'docker'), 'nginx:latest');
  assert.equal(lineKey('docker.io/library/nginx:latest', 'docker'), 'nginx:latest');
  assert.notEqual(lineKey('node:24-alpine', 'docker'), lineKey('node:22-alpine', 'docker'));
  assert.equal(lineKey('ghcr.io/owner/app:1.0', 'docker'), 'ghcr.io/owner/app:1.0');
  assert.equal(lineKey('@angular/core@^22', 'npm'), '@angular/core');
  assert.equal(lineKey('monolog/monolog:^3', 'composer'), 'monolog/monolog');
});

test('cliquer deux fois sur une liste ne crée pas de doublon', () => {
  let text = addLines('', django, 'pypi');
  text = addLines(text, django, 'pypi');
  assert.equal(text, django.join('\n'));
  assert.deepEqual(duplicates(text, 'pypi'), []);
});

test('un paquet déjà saisi autrement n’est pas ajouté une seconde fois', () => {
  const text = addLines('Django>=5\n# mes paquets\nrequests', django, 'pypi');
  assert.equal(text, 'Django>=5\n# mes paquets\nrequests\npsycopg[binary]\ngunicorn\npython-dotenv');
  assert.ok(containsAll(text, django, 'pypi'));
});

test('retirer une liste garde les paquets partagés avec une autre liste cochée', () => {
  let text = addLines(addLines('requests', django, 'pypi'), flask, 'pypi');
  const keep = new Set(django.map((l) => lineKey(l, 'pypi')));
  text = removeLines(text, flask, 'pypi', keep);
  assert.equal(text, 'requests\ndjango\npsycopg[binary]\ngunicorn\npython-dotenv');
  assert.ok(!containsAll(text, flask, 'pypi'));
  assert.ok(containsAll(text, django, 'pypi'));
});

test('retirer une liste conserve commentaires et saisies personnelles', () => {
  const text = removeLines('# projet\nrequests\ndjango\ngunicorn', django, 'pypi');
  assert.equal(text, '# projet\nrequests');
});

test('images Docker : pas de doublon entre « nginx » et « nginx:latest »', () => {
  const text = addLines('nginx', ['nginx:latest', 'redis:7-alpine'], 'docker');
  assert.equal(text, 'nginx\nredis:7-alpine');
});

test('nettoyage des doublons existants : première occurrence gardée', () => {
  const text = 'django\nflask\nDjango>=5\n\ngunicorn\ngunicorn';
  assert.deepEqual(duplicates(text, 'pypi'), ['django', 'gunicorn']);
  assert.equal(removeDuplicates(text, 'pypi'), 'django\nflask\n\ngunicorn');
});
