import { ChangeDetectionStrategy, Component } from '@angular/core';
import { NavItem, Shell } from '@sas/shared';

@Component({
  selector: 'app-layout',
  imports: [Shell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<sas-shell product="Collecteur" icon="globe" [nav]="nav" [online]="true" />`,
})
export class Layout {
  protected readonly nav: NavItem[] = [
    { path: '/', label: 'Tableau de bord', icon: 'dashboard', exact: true },
    { path: '/listes', label: 'Listes', icon: 'list' },
    { path: '/paquets', label: 'Paquets', icon: 'package' },
    { path: '/exports', label: 'Exports', icon: 'usb' },
    { path: '/activite', label: 'Activité', icon: 'activity' },
    { path: '/reglages', label: 'Réglages', icon: 'settings' },
  ];
}
