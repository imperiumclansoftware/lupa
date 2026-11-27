// Tests de la combinaison des listes prédéfinies : node --test projects/collector/tests/spec-lines.test.ts
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  addLines,
  containsAll,
  duplicates,
  lineKey,
  removeDuplicates,
  removeLines,
} from '../src/app/spec-lines.ts';

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
  assert.equal(
    text,
    'Django>=5\n# mes paquets\nrequests\npsycopg[binary]\ngunicorn\npython-dotenv',
  );
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

test('Debian et RPM : nom du paquet, sans version ni stream', () => {
  assert.equal(lineKey('nginx=1.22.1-9', 'deb'), 'nginx');
  assert.equal(lineKey('Curl', 'deb'), 'curl');
  assert.equal(lineKey('nginx = 1.20.1', 'rpm'), 'nginx');
  assert.equal(lineKey('@nodejs:20', 'rpm'), '@nodejs');
  assert.equal(addLines('nginx\ngit', ['nginx=1.26', 'curl'], 'rpm'), 'nginx\ngit\ncurl');
});

test('Ansible Galaxy et Terraform : nom sans contrainte', () => {
  assert.equal(lineKey('community.general >=8.0.0', 'galaxy'), 'community.general');
  assert.equal(lineKey('role:geerlingguy.docker 7.4.1', 'galaxy'), 'role:geerlingguy.docker');
  assert.equal(lineKey('hashicorp/aws ~> 5.0', 'terraform'), 'hashicorp/aws');
  assert.equal(
    addLines('hashicorp/aws', ['hashicorp/aws ~> 5.0', 'terraform ~> 1.9'], 'terraform'),
    'hashicorp/aws\nterraform ~> 1.9',
  );
});

test('extensions VS Code : identifiant sans version ni casse', () => {
  assert.equal(lineKey('MS-Python.Python@2024.2.0', 'vscode'), 'ms-python.python');
  assert.equal(lineKey('redhat.vscode-yaml >=1.14', 'vscode'), 'redhat.vscode-yaml');
  assert.equal(
    addLines(
      'redhat.vscode-yaml\n',
      ['Redhat.vscode-yaml@1.15.0', 'dbaeumer.vscode-eslint'],
      'vscode',
    ),
    'redhat.vscode-yaml\ndbaeumer.vscode-eslint',
  );
});

test('plage [toutes] : distincte d’une contrainte simple sur le même paquet', () => {
  assert.equal(lineKey('react@>=16 <19 [toutes]', 'npm'), 'react [toutes]');
  assert.equal(lineKey('Django>=4,<6 [toutes]', 'pypi'), 'django [toutes]');
  assert.equal(
    lineKey('symfony/console:>=6.4 <8 [toutes]', 'composer'),
    'symfony/console [toutes]',
  );
  const text = addLines('react@^19', ['react@>=16 <=18.3.1 [toutes]'], 'npm');
  assert.equal(text, 'react@^19\nreact@>=16 <=18.3.1 [toutes]');
  assert.equal(addLines(text, ['react@>=17 <=18.3.1 [toutes]'], 'npm'), text);
});
