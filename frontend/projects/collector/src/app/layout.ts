import { ChangeDetectionStrategy, Component } from '@angular/core';
import { NavItem, Shell } from '@sas/shared';

@Component({
  selector: 'app-layout',
  imports: [Shell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<sas-shell brand="Rémus" product="Collecteur de paquets" logo="remus" [nav]="nav" [online]="true" />`,
})
export class Layout {
  protected readonly nav: NavItem[] = [
    { path: '/', label: 'Tableau de bord', icon: 'dashboard', exact: true },
    { path: '/listes', label: 'Listes', icon: 'list' },
    { path: '/paquets', label: 'Paquets', icon: 'package' },
    { path: '/securite', label: 'Sécurité', icon: 'shield' },
    { path: '/exports', label: 'Exports', icon: 'usb' },
    { path: '/activite', label: 'Activité', icon: 'activity' },
    { path: '/reglages', label: 'Réglages', icon: 'settings' },
  ];
}
