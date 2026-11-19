import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { errorMessage } from './api';
import { BytesPipe, DateTimePipe } from './format';
import { Icon } from './icon';

interface LupaDocument {
  id: string;
  title: string;
  description: string;
  restricted: boolean;
  size: number;
  updatedAt: string;
}

/** Documents de Lupa (PDF) : l'API ne liste que ceux que le rôle du compte permet de lire. */
@Component({
  selector: 'sas-documents-page',
  imports: [Icon, BytesPipe, DateTimePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Documents</h1>
          <p>
            Conditions d’utilisation, politique de confidentialité et politiques de sécurité de
            Lupa, au format PDF. Ils ne sont accessibles qu’aux comptes connectés.
          </p>
        </div>
      </header>
      @if (error()) {
        <div class="alert alert-danger" role="alert">{{ error() }}</div>
      }
      <div class="grid docs">
        @for (d of documents(); track d.id) {
          <article class="card card-body doc">
            <div class="head">
              <sas-icon name="file" [size]="22" />
              <h2>{{ d.title }}</h2>
              @if (d.restricted) {
                <span class="badge badge-accent" title="Réservé aux administrateurs"
                  >Administrateurs</span
                >
              }
            </div>
            <p class="subtle">{{ d.description }}</p>
            <p class="meta subtle">PDF · {{ d.size | bytes }} · {{ d.updatedAt | datetime }}</p>
            <div>
              <a class="btn btn-primary" [href]="'/api/documents/' + d.id" download
                ><sas-icon name="download" />Télécharger</a
              >
            </div>
          </article>
        }
      </div>
    </div>
  `,
  styles: `
    .docs {
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
    .doc {
      display: grid;
      gap: 10px;
      align-content: start;
    }
    .head {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .head h2 {
      flex: 1;
      font-size: 1.05rem;
      margin: 0;
    }
    .meta {
      font-size: 0.85rem;
    }
  `,
})
export class DocumentsPage {
  private readonly http = inject(HttpClient);
  protected readonly documents = signal<LupaDocument[]>([]);
  protected readonly error = signal('');

  constructor() {
    firstValueFrom(this.http.get<{ items: LupaDocument[] }>('/api/documents'))
      .then((r) => this.documents.set(r.items))
      .catch((e: unknown) => this.error.set(errorMessage(e)));
  }
}
