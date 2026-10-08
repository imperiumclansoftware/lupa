import { ChangeDetectionStrategy, Component } from '@angular/core';
import { NavItem, Shell } from '@sas/shared';

@Component({
  selector: 'app-layout',
  imports: [Shell],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<sas-shell product="Miroir hors ligne" icon="hdd" [nav]="nav" [online]="false" />`,
})
export class Layout {
  protected readonly nav: NavItem[] = [
    { path: '/', label: 'Tableau de bord', icon: 'dashboard', exact: true },
    { path: '/import', label: 'Import', icon: 'usb' },
    { path: '/paquets', label: 'Paquets', icon: 'package' },
    { path: '/utilisation', label: 'Utilisation', icon: 'terminal' },
    { path: '/activite', label: 'Activité', icon: 'activity' },
    { path: '/reglages', label: 'Réglages', icon: 'key' },
  ];
}
