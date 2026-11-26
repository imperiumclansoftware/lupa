import { Tour } from '@sas/shared';

const help = {
  target: 'aide',
  title: 'Revoir cette aide',
  text: 'Ce bouton relance la visite de la page affichée. Chaque page a la sienne, proposée une seule fois, à votre première visite.',
};

/** Visites guidées de Romulus : une par page, ouverte à la première visite (Aide pour la revoir). */
export const MIRROR_TOURS: Tour[] = [
  {
    id: 'romulus-accueil',
    path: /^\/$/,
    steps: [
      {
        title: 'Bienvenue dans Romulus',
        text: 'Romulus sert, sur le réseau isolé, les paquets collectés par Rémus : registres npm, PyPI, Composer, Docker, dépôts apt et dnf, Ansible, Terraform et extensions VS Code.',
      },
      {
        target: 'navigation',
        title: 'Navigation',
        text: 'Import : les bundles de la clé USB. Paquets : ce qui est servi. Utilisation : la configuration de chaque outil sur les postes.',
      },
      {
        target: 'accueil',
        title: 'État du miroir',
        text: 'Le contenu servi et l’accès direct à l’import d’un nouveau bundle.',
      },
      {
        target: 'ecosystemes',
        title: 'Par écosystème',
        text: 'Le nombre de paquets servis pour chaque écosystème ; un clic ouvre les paquets correspondants.',
      },
      {
        target: 'serveur',
        title: 'Serveur',
        text: 'L’adresse à donner aux postes et l’espace disque restant.',
      },
      help,
    ],
  },
  {
    id: 'romulus-import',
    path: /^\/import$/,
    steps: [
      {
        target: 'bundles',
        title: 'Bundles disponibles',
        text: 'Les bundles trouvés sur la clé USB. La signature et chaque fichier sont vérifiés avant d’écrire quoi que ce soit ; au premier import, approuvez le collecteur après avoir comparé l’empreinte de sa clé.',
      },
      {
        target: 'envoi',
        title: 'Envoyer depuis cet ordinateur',
        text: 'Si la clé USB est branchée sur votre poste plutôt que sur le serveur, glissez-y les fichiers du bundle.',
      },
      {
        target: 'historique',
        title: 'Historique',
        text: 'Les imports passés, leur collecteur et leur contenu.',
      },
    ],
  },
  {
    id: 'romulus-paquets',
    path: /^\/paquets$/,
    steps: [
      {
        target: 'filtres',
        title: 'Écosystèmes',
        text: 'Affichez les paquets d’un seul écosystème.',
      },
      {
        target: 'paquets',
        title: 'Paquets servis',
        text: 'Tout ce que Romulus sert, avec ses alertes de sécurité. Une version en quarantaine (liste noire de Rémus) n’est plus servie.',
      },
    ],
  },
  {
    id: 'romulus-utilisation',
    path: /^\/utilisation$/,
    steps: [
      {
        target: 'outils',
        title: 'Outils',
        text: 'Choisissez l’outil à configurer sur les postes : npm, pip, Composer, Docker, apt, dnf, Ansible, Terraform ou VS Code.',
      },
      {
        target: 'configuration',
        title: 'Configuration',
        text: 'Les fichiers et commandes à copier sur les postes, avec l’adresse de ce miroir déjà renseignée.',
      },
    ],
  },
  {
    id: 'romulus-reglages',
    path: /^\/reglages$/,
    steps: [
      {
        target: 'onglets',
        title: 'Rubriques',
        text: 'Les collecteurs dont les bundles sont acceptés, la liste noire et sa quarantaine, le stockage (disque ou S3, pour les administrateurs) et la version de Romulus.',
      },
    ],
  },
  {
    id: 'romulus-compte',
    path: /^\/compte$/,
    steps: [
      {
        title: 'Mon compte',
        text: 'Changez votre mot de passe, activez la double authentification (application TOTP hors ligne) et faites réapparaître les visites guidées.',
      },
    ],
  },
];
