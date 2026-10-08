import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BytesPipe, CopyButton, EcosystemPipe, Icon, RelativePipe, errorMessage } from '@sas/shared';
import { MirrorApi, PackageDetail } from '../api';
import { installCommand } from '../clients';

@Component({
  imports: [RouterLink, Icon, CopyButton, BytesPipe, RelativePipe, EcosystemPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <a class="subtle back" routerLink="/paquets"><sas-icon name="back" [size]="15" />Paquets</a>
          <h1 class="name">{{ name() }}</h1>
          @if (data(); as d) {
            <p class="row meta"><span class="eco-dot" [style.background]="'var(--eco-' + d.ecosystem + ')'"></span>{{ d.ecosystem | ecosystem }}
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
        <section class="card">
          <header class="card-header"><h2>{{ d.ecosystem === 'docker' ? 'Étiquettes' : 'Versions' }}</h2></header>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>{{ d.ecosystem === 'docker' ? 'Étiquette' : 'Version' }}</th><th>{{ d.ecosystem === 'pypi' ? 'Fichier' : 'Détail' }}</th><th class="right">Taille</th><th>Bundle</th><th>Reçu</th><th></th></tr></thead>
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
    .detail { color: var(--text-2); font-size: 0.86rem; overflow-wrap: anywhere; }
  `,
})
export default class PackagePage implements OnInit {
  readonly ecosystem = input.required<string>();
  readonly name = input.required<string>();
  private readonly api = inject(MirrorApi);
  protected readonly data = signal<PackageDetail | null>(null);
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
