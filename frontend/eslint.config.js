// @ts-check
// Analyse statique du front : angular-eslint + typescript-eslint strict, et règles de sécurité de l'équipe
const eslint = require('@eslint/js');
const tseslint = require('typescript-eslint');
const angular = require('angular-eslint');

module.exports = tseslint.config(
  { ignores: ['dist/**', '.angular/**', 'node_modules/**'] },
  {
    files: ['**/*.ts'],
    extends: [
      eslint.configs.recommended,
      ...tseslint.configs.strict,
      ...tseslint.configs.stylistic,
      ...angular.configs.tsRecommended,
    ],
    processor: angular.processInlineTemplates,
    rules: {
      // Sécurité (guidelines) : ni contournement de l'assainissement Angular, ni HTML injecté, ni stockage navigateur
      'no-restricted-syntax': [
        'error',
        {
          selector: 'MemberExpression[property.name=/^bypassSecurityTrust/]',
          message: 'bypassSecurityTrust* interdit : passer par le binding Angular.',
        },
        {
          selector: 'MemberExpression[property.name=/^(innerHTML|outerHTML)$/]',
          message: 'innerHTML/outerHTML interdits : utiliser le binding ou Renderer2.',
        },
        {
          selector: "CallExpression[callee.property.name='insertAdjacentHTML']",
          message: 'insertAdjacentHTML interdit.',
        },
      ],
      'no-restricted-properties': [
        'error',
        { object: 'Math', property: 'random', message: 'Utiliser crypto.getRandomValues().' },
        { object: 'document', property: 'write' },
      ],
      'no-restricted-globals': ['error', 'localStorage', 'sessionStorage', 'eval'],
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      '@angular-eslint/component-selector': [
        'error',
        { type: 'element', prefix: 'sas', style: 'kebab-case' },
      ],
      '@angular-eslint/directive-selector': [
        'error',
        { type: 'attribute', prefix: 'sas', style: 'camelCase' },
      ],
      // Composants Angular sans membre (racine, mise en page) : la classe porte le décorateur
      '@typescript-eslint/no-extraneous-class': ['error', { allowWithDecorator: true }],
      // output<void>() est l'écriture Angular d'un événement sans valeur
      '@typescript-eslint/no-invalid-void-type': 'off',
    },
  },
  {
    files: ['**/*.html'],
    extends: [...angular.configs.templateRecommended, ...angular.configs.templateAccessibility],
    rules: {
      // Assistant d'installation et connexion : un seul champ à saisir, le focus initial y est attendu
      '@angular-eslint/template/no-autofocus': 'off',
    },
  },
  {
    // Tests unitaires Node (node --test)
    files: ['projects/*/tests/**/*.ts'],
    rules: { '@typescript-eslint/no-non-null-assertion': 'off' },
  },
);
