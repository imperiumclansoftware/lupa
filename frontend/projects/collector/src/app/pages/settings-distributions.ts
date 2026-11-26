import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Auth, Dialog, Eco, Icon, Toasts, errorMessage } from '@sas/shared';
import { CollectorApi, CustomDistribution, CustomDistributionInput } from '../api';

/** Réglages › Distributions personnalisées : dérivées internes, distributions absentes du catalogue. */
@Component({
  selector: 'sas-settings-distributions',
  imports: [FormsModule, Icon, Eco, Dialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card" id="distributions">
      <header class="card-header">
        <div>
          <h2>Distributions personnalisées</h2>
          <p class="subtle">
            Une distribution absente du catalogue (dérivée interne, autre version) : ses dépôts, sa
            clé de signature, ses architectures. Elle se choisit ensuite dans les listes Debian ou
            RPM, et Romulus la sert comme les autres.
          </p>
        </div>
        @if (auth.can('admin')) {
          <button type="button" class="btn btn-primary" (click)="edit()">
            <sas-icon name="plus" />Ajouter une distribution
          </button>
        }
      </header>
      @if (error()) {
        <div class="card-body">
          <div class="alert alert-danger">{{ error() }}</div>
        </div>
      }
      @if (items().length === 0) {
        <div class="empty">
          <sas-icon name="hdd" [size]="28" />
          <h3>Aucune distribution personnalisée</h3>
          <p>
            Les distributions intégrées (Debian, Ubuntu, AlmaLinux, Rocky Linux, CentOS) suffisent.
          </p>
        </div>
      } @else {
        <ul class="items">
          @for (d of items(); track d.id) {
            <li>
              <sas-eco [ecosystem]="d.ecosystem" [size]="28" />
              <span class="what">
                <strong>{{ d.label }}</strong>
                <span class="badge mono">{{ d.slug }}</span>
                @if (d.eol) {
                  <span class="badge badge-warn">fin de vie</span>
                }
                <br /><span class="subtle"
                  >{{ d.ecosystem === 'deb' ? 'apt' : 'dnf' }} · version {{ d.release
                  }}{{ d.codename ? ' (' + d.codename + ')' : '' }} ·
                  {{ d.architectures.join(', ') }}</span
                >
                @if (d.keyFingerprint) {
                  <br /><span class="subtle"
                    >Clé <span class="mono">{{ shortFingerprint(d.keyFingerprint) }}</span> ·
                    {{ d.usedBy.lists }} liste(s), {{ d.usedBy.sources }} dépôt(s) tiers</span
                  >
                }
              </span>
              @if (auth.can('admin')) {
                <button
                  type="button"
                  class="btn btn-ghost btn-icon btn-sm"
                  (click)="edit(d)"
                  [attr.aria-label]="'Modifier ' + d.label"
                >
                  <sas-icon name="edit" />
                </button>
                <button
                  type="button"
                  class="btn btn-ghost btn-icon btn-sm"
                  (click)="remove(d)"
                  [attr.aria-label]="'Supprimer ' + d.label"
                >
                  <sas-icon name="trash" />
                </button>
              }
            </li>
          }
        </ul>
      }
    </section>

    <sas-dialog
      #dialog
      [heading]="form.id ? 'Modifier la distribution' : 'Nouvelle distribution personnalisée'"
    >
      <form id="distribution-form" class="stack" (ngSubmit)="save()">
        @if (formError()) {
          <div class="alert alert-danger">{{ formError() }}</div>
        }
        <div class="grid two">
          <div class="field">
            <label for="dist-type">Type</label>
            <select
              class="select"
              id="dist-type"
              name="ecosystem"
              [(ngModel)]="form.ecosystem"
              [disabled]="!!form.id"
              (ngModelChange)="tested.set(null)"
            >
              <option value="deb">Debian, Ubuntu (apt)</option>
              <option value="rpm">RPM (dnf, yum)</option>
            </select>
          </div>
          <div class="field">
            <label for="dist-slug">Identifiant</label>
            <input
              class="input mono"
              id="dist-slug"
              name="slug"
              required
              maxlength="32"
              [(ngModel)]="form.slug"
              [readonly]="!!form.id"
              [placeholder]="form.ecosystem === 'deb' ? 'debian-12-interne' : 'rocky-9-interne'"
            />
            <span class="hint">Repris dans les adresses de Romulus ; ne peut plus changer.</span>
          </div>
        </div>
        <div class="grid two">
          <div class="field">
            <label for="dist-label">Nom</label>
            <input
              class="input"
              id="dist-label"
              name="label"
              required
              maxlength="120"
              [(ngModel)]="form.label"
              placeholder="Debian 12 de l’entreprise"
            />
          </div>
          <div class="field">
            <label for="dist-release">Version</label>
            <input
              class="input mono"
              id="dist-release"
              name="release"
              required
              [(ngModel)]="form.release"
              placeholder="12"
            />
          </div>
        </div>
        <div class="grid two">
          @if (form.ecosystem === 'deb') {
            <div class="field">
              <label for="dist-codename">Nom de code</label>
              <input
                class="input mono"
                id="dist-codename"
                name="codename"
                required
                [(ngModel)]="form.codename"
                placeholder="bookworm"
              />
              <span class="hint">Suite des postes (« Suites: » de romulus.sources).</span>
            </div>
          }
          <div class="field">
            <label for="dist-arch">Architectures</label>
            <input
              class="input mono"
              id="dist-arch"
              name="architectures"
              required
              [(ngModel)]="form.architectures"
              [placeholder]="form.ecosystem === 'deb' ? 'amd64 arm64' : 'x86_64 aarch64'"
            />
          </div>
        </div>
        @if (form.ecosystem === 'deb') {
          <div class="field">
            <label for="dist-sections">Composants</label>
            <input
              class="input mono"
              id="dist-sections"
              name="sections"
              required
              [(ngModel)]="form.sections"
              placeholder="main contrib"
            />
          </div>
        }
        <div class="field">
          <label for="dist-sources">{{ form.ecosystem === 'deb' ? 'Suites' : 'Dépôts' }}</label>
          <textarea
            class="input mono"
            id="dist-sources"
            name="sources"
            rows="3"
            required
            [(ngModel)]="form.sources"
            (ngModelChange)="tested.set(null)"
            [placeholder]="
              form.ecosystem === 'deb'
                ? 'https://apt.example.com/debian bookworm\\nhttps://apt.example.com/debian bookworm-updates'
                : 'BaseOS https://rpm.example.com/9/BaseOS/$basearch/os\\nAppStream https://rpm.example.com/9/AppStream/$basearch/os'
            "
          ></textarea>
          <span class="hint">{{
            form.ecosystem === 'deb'
              ? 'Une suite par ligne : adresse du dépôt, puis nom de la suite (fusionnées chez Romulus).'
              : 'Un dépôt par ligne : nom, puis adresse avec $basearch pour l’architecture.'
          }}</span>
        </div>
        <div class="field">
          <label for="dist-key">Clé de signature</label>
          <textarea
            class="input mono key"
            id="dist-key"
            name="signingKey"
            rows="5"
            [(ngModel)]="form.signingKey"
            (ngModelChange)="tested.set(null)"
            [placeholder]="
              fingerprint()
                ? 'Inchangée : ' + fingerprint()
                : '-----BEGIN PGP PUBLIC KEY BLOCK-----'
            "
          ></textarea>
          <span class="hint"
            >Clé publique OpenPGP qui signe les index (apt, dnf) ou, à défaut, chaque paquet RPM.
            Comparez son empreinte à celle de l’éditeur.</span
          >
        </div>
        <label class="check"
          ><input type="checkbox" name="eol" [(ngModel)]="form.eol" /><span
            >Fin de vie (signalée dans les formulaires)</span
          ></label
        >
        @if (tested(); as t) {
          <div class="alert result" [class.alert-ok]="t.ok" [class.alert-danger]="!t.ok">
            {{ t.message }}
          </div>
        }
      </form>
      <ng-container dialog-actions>
        <button type="button" class="btn btn-ghost" (click)="test()" [disabled]="testing()">
          <sas-icon name="refresh" />{{ testing() ? 'Test…' : 'Tester' }}
        </button>
        <button type="button" class="btn btn-ghost" (click)="dialog.close()">Annuler</button>
        <button type="submit" form="distribution-form" class="btn btn-primary" [disabled]="busy()">
          Enregistrer
        </button>
      </ng-container>
    </sas-dialog>
  `,
  styles: `
    .card-header p {
      margin: 4px 0 0;
    }
    .items {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .items li {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 24px;
      border-top: 1px solid var(--border);
    }
    .what {
      flex: 1;
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .what .badge {
      margin-left: 6px;
    }
    .two {
      grid-template-columns: 1fr 1fr;
    }
    .key {
      font-size: 12px;
      resize: vertical;
    }
    .result {
      white-space: pre-line;
    }
    @media (max-width: 600px) {
      .two {
        grid-template-columns: 1fr;
      }
    }
  `,
})
export class SettingsDistributions implements OnInit {
  protected readonly auth = inject(Auth);
  private readonly api = inject(CollectorApi);
  private readonly toasts = inject(Toasts);
  private readonly dialog = viewChild.required<Dialog>('dialog');
  protected readonly items = signal<CustomDistribution[]>([]);
  protected readonly error = signal('');
  protected readonly formError = signal('');
  protected readonly busy = signal(false);
  protected readonly testing = signal(false);
  protected readonly tested = signal<{ ok: boolean; message: string } | null>(null);
  protected readonly fingerprint = signal<string | null>(null);
  protected form: CustomDistributionInput = SettingsDistributions.blank();

  private static blank(): CustomDistributionInput {
    return {
      ecosystem: 'deb',
      slug: '',
      label: '',
      release: '',
      codename: null,
      eol: false,
      architectures: '',
      sections: null,
      sources: '',
      signingKey: null,
    };
  }

  ngOnInit(): void {
    void this.load();
  }

  /** Les 16 derniers caractères, par groupes de 4, comme gpg --list-keys */
  protected shortFingerprint(fingerprint: string): string {
    return fingerprint.slice(-16).replace(/(.{4})(?!$)/g, '$1 ');
  }

  protected edit(d?: CustomDistribution): void {
    this.form = d
      ? {
          id: d.id,
          ecosystem: d.ecosystem,
          slug: d.slug,
          label: d.label,
          release: d.release,
          codename: d.codename,
          eol: d.eol,
          architectures: d.architectures.join(' '),
          sections: d.sections.join(' '),
          sources: d.sources,
          signingKey: null,
        }
      : SettingsDistributions.blank();
    this.fingerprint.set(d?.keyFingerprint ?? null);
    this.formError.set('');
    this.tested.set(null);
    this.dialog().open();
  }

  protected async save(): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.saveCustomDistribution(this.payload());
      this.api.forgetEcosystems();
      this.toasts.ok('Distribution enregistrée.');
      this.dialog().close();
      await this.load();
    } catch (e) {
      this.formError.set(errorMessage(e));
    } finally {
      this.busy.set(false);
    }
  }

  protected async test(): Promise<void> {
    this.testing.set(true);
    try {
      this.tested.set(await this.api.testCustomDistribution(this.payload()));
    } catch (e) {
      this.tested.set({ ok: false, message: errorMessage(e) });
    } finally {
      this.testing.set(false);
    }
  }

  protected async remove(d: CustomDistribution): Promise<void> {
    if (!confirm(`Supprimer la distribution « ${d.label} » ?`)) return;
    try {
      await this.api.deleteCustomDistribution(d.id);
      this.api.forgetEcosystems();
      this.toasts.ok('Distribution supprimée.');
      await this.load();
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }

  private payload(): CustomDistributionInput {
    const f = this.form;
    const deb = f.ecosystem === 'deb';
    return {
      ...f,
      slug: f.slug.trim(),
      codename: deb ? f.codename?.trim() || null : null,
      sections: deb ? f.sections?.trim() || null : null,
      signingKey: f.signingKey?.trim() || null,
    };
  }

  private async load(): Promise<void> {
    try {
      this.items.set((await this.api.customDistributions()).items);
      this.error.set('');
    } catch (e) {
      this.error.set(errorMessage(e));
    }
  }
}
