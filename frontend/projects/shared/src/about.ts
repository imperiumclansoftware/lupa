import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { Icon } from './icon';

export interface About {
  application: string;
  role: string;
  version: string;
  symfony: string;
  php: string;
  publisher: string;
  license: string;
  repository: string;
}

/** Réglages › À propos : version de l'application (Rémus ou Romulus). */
@Component({
  selector: 'sas-about',
  imports: [Icon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card card-body stack" id="a-propos">
      <div class="row">
        <sas-icon name="file" />
        <h2>À propos</h2>
      </div>
      @if (error()) {
        <div class="alert alert-danger">{{ error() }}</div>
      }
      @if (about(); as a) {
        <p class="muted">{{ a.application }}, {{ a.role }}.</p>
        <div class="kv">
          <span class="subtle">Version</span>
          <span>
            <strong class="mono">{{ a.version === 'dev' ? 'développement' : a.version }}</strong>
            @if (a.version === 'dev') {
              <span class="badge">hors version publiée</span>
            }
          </span>
          <span class="subtle">Éditeur</span><span>{{ a.publisher }}</span>
          <span class="subtle">Licence</span><span>{{ a.license }}</span>
          <span class="subtle">Sources</span>
          <a [href]="a.repository" target="_blank" rel="noopener noreferrer">{{ a.repository }}</a>
          <span class="subtle">Socle</span
          ><span class="mono">Symfony {{ a.symfony }} · PHP {{ a.php }}</span>
        </div>
      }
    </section>
  `,
  styles: `
    .kv {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 8px 16px;
      align-items: baseline;
    }
    .kv a {
      overflow-wrap: anywhere;
    }
    .kv .badge {
      margin-left: 8px;
    }
  `,
})
export class AboutCard implements OnInit {
  private readonly http = inject(HttpClient);
  protected readonly about = signal<About | null>(null);
  protected readonly error = signal('');

  ngOnInit(): void {
    firstValueFrom(this.http.get<About>('/api/about')).then(
      (a) => this.about.set(a),
      (e: unknown) => this.error.set(errorMessage(e)),
    );
  }
}
