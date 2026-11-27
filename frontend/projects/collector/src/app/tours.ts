import { Tour } from '@sas/shared';

const help = {
  target: 'aide',
  title: 'Revoir cette aide',
  text: 'Ce bouton relance la visite de la page affichée. Chaque page a la sienne, proposée une seule fois, à votre première visite.',
};

/** Visites guidées de Rémus : une par page, ouverte à la première visite (Aide pour la revoir). */
export const COLLECTOR_TOURS: Tour[] = [
  {
    id: 'remus-accueil',
    path: /^\/$/,
    steps: [
      {
        title: 'Bienvenue dans Rémus',
        text: 'Rémus télécharge les paquets dont votre réseau isolé a besoin, dépendances comprises, puis les prépare en bundles signés à copier sur une clé USB pour Romulus.',
      },
      {
        target: 'navigation',
        title: 'Navigation',
        text: 'Listes : ce qu’il faut collecter. Paquets et Sécurité : ce qui a été téléchargé et ses failles connues. Exports : les bundles pour la clé USB.',
      },
      {
        target: 'accueil',
        title: 'État du collecteur',
        text: 'Le nom du collecteur, les paquets qui attendent un export et l’accès direct au prochain bundle.',
      },
      {
        target: 'ecosystemes',
        title: 'Par écosystème',
        text: 'Le nombre de paquets collectés pour chaque écosystème ; un clic ouvre les paquets correspondants.',
      },
      {
        target: 'alertes',
        title: 'Alertes de sécurité',
        text: 'Les failles connues (OSV.dev) des paquets collectés, avec leur gravité.',
      },
      help,
    ],
  },
  {
    id: 'remus-listes',
    path: /^\/listes$/,
    steps: [
      {
        target: 'nouvelle-liste',
        title: 'Créer une liste',
        text: 'Une liste rassemble les paquets à collecter pour un écosystème (npm, Python, Debian, extensions VS Code…) : saisis, importés d’un fichier de verrouillage ou pris dans un modèle.',
      },
      {
        target: 'listes',
        title: 'Vos listes',
        text: 'Pour chacune : son nombre de paquets, sa dernière mise à jour et son état. Ouvrez-la pour voir son contenu ou la mettre à jour.',
      },
    ],
  },
  {
    id: 'remus-liste-formulaire',
    path: /^\/listes\/(nouvelle|[^/]+\/modifier)$/,
    steps: [
      {
        target: 'ecosystemes',
        title: 'Écosystème',
        text: 'Le type de paquets : il détermine la syntaxe des lignes, les options et les modèles proposés.',
      },
      {
        target: 'modeles',
        title: 'Listes prêtes à l’emploi',
        text: 'Des sélections courantes (serveur web, outils de développement…) à ajouter en un clic, puis à compléter.',
      },
      {
        target: 'saisie',
        title: 'Paquets',
        text: 'Un paquet par ligne, avec une contrainte de version facultative. La recherche aide à trouver noms et versions ; les dépendances sont ajoutées automatiquement. Pour toutes les versions d’une plage (npm, Python, Composer), terminez la ligne par [toutes] : react@>=16 <19 [toutes].',
      },
      {
        target: 'options',
        title: 'Options',
        text: 'Plateformes, architectures, distribution ou sources selon l’écosystème : elles déterminent les fichiers téléchargés.',
      },
    ],
  },
  {
    id: 'remus-liste',
    path: /^\/listes\/(?!nouvelle$)[^/]+$/,
    steps: [
      {
        target: 'mettre-a-jour',
        title: 'Mettre à jour',
        text: 'Recalcule les dépendances et télécharge ce qui manque. La tâche tourne en arrière-plan ; sa progression s’affiche sur la page.',
      },
      {
        target: 'contenu',
        title: 'Contenu',
        text: 'Tous les paquets retenus, dépendances comprises, avec leur taille, leurs alertes de sécurité et leur état d’export.',
      },
    ],
  },
  {
    id: 'remus-paquets',
    path: /^\/paquets$/,
    steps: [
      {
        target: 'filtres',
        title: 'Écosystèmes',
        text: 'Affichez les paquets d’un seul écosystème.',
      },
      {
        target: 'paquets',
        title: 'Paquets collectés',
        text: 'Tout ce que Rémus a téléchargé : listes d’origine, failles connues et état d’export (« à exporter » tant qu’aucun bundle ne l’a transporté).',
      },
    ],
  },
  {
    id: 'remus-securite',
    path: /^\/securite$/,
    steps: [
      {
        target: 'analyse',
        title: 'Nouvelle analyse',
        text: 'De nouvelles failles sont publiées chaque jour : relancez l’analyse avant un export pour en tenir compte.',
      },
      {
        target: 'alertes',
        title: 'Niveaux d’alerte',
        text: 'Paquets critiques, avertissements et failles de faible gravité ; un clic filtre la liste.',
      },
      {
        target: 'vulnerables',
        title: 'Paquets vulnérables',
        text: 'Chaque faille, la version qui la corrige, et « Interdire » pour mettre la version en liste noire : elle n’est plus exportée et Romulus la met en quarantaine.',
      },
    ],
  },
  {
    id: 'remus-exports',
    path: /^\/exports$/,
    steps: [
      {
        target: 'nouveau-bundle',
        title: 'Nouveau bundle',
        text: 'Un export incrémental transporte seulement ce qui n’a jamais été exporté ; un export complet, tout. Les gros bundles sont découpés en parties.',
      },
      {
        target: 'historique',
        title: 'Historique',
        text: 'Les bundles produits et leurs fichiers, téléchargeables si la clé USB n’est pas branchée sur ce serveur.',
      },
      {
        target: 'transport',
        title: 'Transporter',
        text: 'Copiez les fichiers sur la clé USB, puis importez-les dans Romulus : il vérifie la signature avant d’écrire quoi que ce soit.',
      },
    ],
  },
  {
    id: 'remus-reglages',
    path: /^\/reglages$/,
    steps: [
      {
        target: 'onglets',
        title: 'Rubriques',
        text: 'Identité et clé de signature, dépôts sources (privés ou tiers), distributions personnalisées, liste noire, stockage (disque ou S3) et intégrité des paquets.',
      },
      {
        title: 'Selon votre rôle',
        text: 'Certains réglages sont en lecture seule pour les lecteurs et les opérateurs ; les administrateurs gèrent le stockage, les dépôts et les comptes.',
      },
    ],
  },
  {
    id: 'remus-compte',
    path: /^\/compte$/,
    steps: [
      {
        title: 'Mon compte',
        text: 'Changez votre mot de passe, activez la double authentification (application TOTP hors ligne) et faites réapparaître les visites guidées.',
      },
    ],
  },
];
