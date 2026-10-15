import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, SeverityBadge, CopyButton, EcosystemPipe, Icon, RelativePipe, errorMessage, Eco } from '@sas/shared';
import { MirrorApi, PackageDetail } from '../api';
import { installCommand } from '../clients';

@Component({
  imports: [Eco, SeverityBadge, RouterLink, Icon, CopyButton, BytesPipe, RelativePipe, EcosystemPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <a class="subtle back" routerLink="/paquets"><sas-icon name="back" [size]="15" />Paquets</a>
          <h1 class="name">{{ name() }}</h1>
          @if (data(); as d) {
            <p class="row meta"><sas-eco [ecosystem]="d.ecosystem" [size]="26" />{{ d.ecosystem | ecosystem }}
              @if (d.description) { <span>·</span>{{ d.description }} }</p>
          }
        </div>
      </header>

      @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

      @if (data(); as d) {
        @if (d.items[0]; as latest) {
          <section class="card card-body stack">
            <h2>Installer</h2>
            <div class="cmd"><pre class="kbd-block">{{ command(latest.version) }}</pre><sas-copy [text]="command(latest.version)" /></div>
            <a routerLink="/utilisation" class="subtle">Configurer un poste pour utiliser ce miroir</a>
          </section>
        }
        @if (vulnerable().length) {
          <section class="card">
            <header class="card-header"><div class="row"><sas-icon name="alert" /><h2>Alertes de sécurité</h2></div>
              <span class="subtle">Analyse faite par Rémus avant l’export</span></header>
            <ul class="vulns">
              @for (v of vulnerable(); track v.id) {
                <li>
                  <div class="row"><span class="mono">{{ v.version }}</span><sas-severity [severity]="v.severity" [count]="v.vulnerabilities.length" />
                    @if (v.auditedAt) { <span class="subtle">analysé {{ v.auditedAt | relative }}</span> }</div>
                  @for (x of v.vulnerabilities; track x.id) {
                    <div class="vuln">
                      <sas-severity [severity]="x.severity" />
                      <span class="mono">{{ x.id }}</span>
                      <span class="grow">{{ x.summary }}</span>
                      <span class="subtle">corrigée en <span class="mono">{{ x.fixed.length ? x.fixed.join(', ') : '—' }}</span></span>
                    </div>
                  }
                </li>
              }
            </ul>
          </section>
        }

        <section class="card">
          <header class="card-header"><h2>{{ d.ecosystem === 'docker' ? 'Étiquettes' : 'Versions' }}</h2></header>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>{{ d.ecosystem === 'docker' ? 'Étiquette' : 'Version' }}</th><th>{{ d.ecosystem === 'pypi' ? 'Fichier' : 'Détail' }}</th><th class="right">Taille</th><th>Sécurité</th><th>Bundle</th><th>Reçu</th><th></th></tr></thead>
              <tbody>
                @for (v of d.items; track v.id) {
                  <tr>
                    <td class="mono">{{ v.version }}</td>
                    <td class="detail">
                      @if (v.filename) { <span class="mono">{{ v.filename }}</span> }
                      {{ v.detail }}
                      @if (v.digest) { <div class="subtle mono">{{ v.digest.slice(0, 19) }}…</div> }
                    </td>
                    <td class="right num">{{ v.size | bytes }}</td>
                    <td><sas-severity [severity]="v.severity" [count]="v.vulnerabilities.length" /></td>
                    <td>@if (v.bundleSequence) { <span class="badge">n° {{ v.bundleSequence }}</span> }</td>
                    <td class="subtle">{{ v.updatedAt | relative }}</td>
                    <td class="right"><sas-copy [text]="command(v.version)" /></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </section>
      }
    </div>
  `,
  styles: `
    .back { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; margin-bottom: 6px; }
    .name { overflow-wrap: anywhere; }
    .meta { flex-wrap: wrap; color: var(--text-2); }
    .cmd { display: flex; gap: 8px; align-items: flex-start; }
    .cmd pre { flex: 1; padding-right: 16px; }
    .vulns { list-style: none; margin: 0; padding: 0 24px 18px; display: grid; gap: 14px; }
    .vuln { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; padding: 6px 0 0 12px; font-size: 0.88rem; }
    .vuln .grow { flex: 1; min-width: 220px; }
    .detail { color: var(--text-2); font-size: 0.86rem; overflow-wrap: anywhere; }
  `,
})
export default class PackagePage implements OnInit {
  readonly ecosystem = input.required<string>();
  readonly name = input.required<string>();
  private readonly api = inject(MirrorApi);
  protected readonly data = signal<PackageDetail | null>(null);
  /** Versions signalées, sans doublon (plusieurs fichiers Python par version) */
  protected readonly vulnerable = computed(() => {
    const seen = new Set<string>();
    return (this.data()?.items ?? []).filter((v) => v.severity && !seen.has(v.version) && !!seen.add(v.version));
  });
  protected readonly error = signal('');

  async ngOnInit(): Promise<void> {
    try {
      this.data.set(await this.api.package(this.ecosystem(), this.name()));
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  protected command(version: string): string {
    const d = this.data();
    return d ? installCommand(d.ecosystem, d.name, version) : '';
  }
}
