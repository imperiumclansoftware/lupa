import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { EcosystemId, Job, SecurityCounts, Severity, Vulnerability } from '@sas/shared';
import { firstValueFrom } from 'rxjs';

export interface Identity {
  id: string;
  name: string;
  publicKey: string;
  fingerprint: string;
}

export interface PypiTarget {
  python: string;
  platform: string;
}

export interface ListTemplate {
  id: string;
  version: string;
}

export interface Template {
  id: string;
  ecosystem: EcosystemId;
  kind: 'generator' | 'preset';
  label: string;
  description: string;
  version?: string;
  versionHint?: string;
  usage?: string;
  specs?: string;
}

export interface ListOptions {
  template?: ListTemplate | null;
  includeDev?: boolean;
  legacyPeerDeps?: boolean;
  targets?: PypiTarget[];
  sdist?: boolean;
  php?: string | null;
  platforms?: string[];
}

export interface PackageList {
  id: number;
  name: string;
  ecosystem: EcosystemId;
  specCount: number;
  lockfileName: string | null;
  options: ListOptions;
  artifactCount: number;
  size: number;
  pendingCount: number;
  security: SecurityCounts;
  createdAt: string;
  updatedAt: string;
  resolvedAt: string | null;
  job: Job | null;
  specs?: string;
  hasLockfile?: boolean;
}

export interface ListInput {
  name: string;
  ecosystem: EcosystemId;
  specs: string;
  options: ListOptions;
  lockfileName?: string | null;
  lockfileContent?: string | null;
  removeLockfile?: boolean;
}

export interface Artifact {
  id: number;
  ecosystem: EcosystemId;
  name: string;
  version: string;
  filename: string | null;
  size: number;
  detail: string | null;
  exportSequence: number | null;
  severity: Severity | null;
  vulnerabilityCount: number;
  auditedAt: string | null;
  vulnerabilities?: Vulnerability[];
  updatedAt: string;
  blacklisted: BlacklistEntry | null;
}

export interface BlacklistEntry {
  id: number;
  ecosystem: EcosystemId;
  name: string;
  version: string;
  reason: string;
  createdAt: string;
  collected?: number;
}

export interface BlacklistInput {
  ecosystem: EcosystemId;
  name: string;
  version: string;
  reason: string;
}

export interface Blacklist {
  revision: number;
  exportedRevision: number;
  pending: boolean;
  updatedAt: string | null;
  items: BlacklistEntry[];
}

export type AuthType = 'none' | 'basic' | 'token';

export interface Source {
  id: number;
  ecosystem: EcosystemId;
  label: string;
  url: string;
  scope: string | null;
  authType: AuthType;
  username: string | null;
  hasSecret: boolean;
  replacesDefault: boolean;
  enabled: boolean;
  createdAt: string;
}

export interface SourceInput {
  id?: number;
  ecosystem: EcosystemId;
  label: string;
  url: string;
  scope: string | null;
  authType: AuthType;
  username: string | null;
  /** vide = inchangé */
  secret: string | null;
  replacesDefault: boolean;
  enabled: boolean;
}

export interface SecurityItem extends Artifact {
  vulnerabilities: Vulnerability[];
  lists: { id: number; name: string }[];
}

export interface SecurityReport {
  counts: SecurityCounts;
  auditable: number;
  audited: number;
  oldestAudit: string | null;
  job: Job | null;
  items: SecurityItem[];
}

export interface SearchResult {
  name: string;
  description: string | null;
  version: string | null;
  info: string | null;
}

export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  pageSize: number;
}

export interface ExportFile {
  name: string;
  size: number | null;
  present: boolean;
}

export interface Export {
  id: number;
  sequence: number;
  full: boolean;
  baseName: string;
  files: ExportFile[];
  size: number;
  artifactCount: number;
  blobCount: number;
  summary: Partial<Record<EcosystemId, number>>;
  maxPartSize: number | null;
  createdAt: string;
  job: Job | null;
}

export interface Pending {
  artifacts: number;
  blobs: number;
  size: number;
}

export interface Exports {
  exportDir: string;
  problem: string | null;
  freeSpace: number | null;
  pending: Pending;
  full: Pending;
  blacklist: { revision: number; pending: boolean; entries: number };
  items: Export[];
}

export interface Overview {
  identity: Identity;
  ecosystems: Partial<Record<EcosystemId, { artifacts: number; size: number; pending: number }>>;
  lists: number;
  storage: { blobs: number; size: number; free: number | null };
  lastExport: Export | null;
  security: SecurityCounts;
  activeJobs: Job[];
  recentJobs: Job[];
}

export interface Choice {
  id: string;
  label: string;
}

export interface EcosystemChoices {
  ecosystems: Choice[];
  pypi: { pythonVersions: string[]; platforms: Choice[] };
  docker: { platforms: Choice[] };
}

@Injectable({ providedIn: 'root' })
export class CollectorApi {
  private readonly http = inject(HttpClient);
  private choices?: Promise<EcosystemChoices>;
  private templateList?: Promise<Template[]>;

  overview(): Promise<Overview> {
    return firstValueFrom(this.http.get<Overview>('/api/overview'));
  }

  ecosystems(): Promise<EcosystemChoices> {
    return (this.choices ??= firstValueFrom(this.http.get<EcosystemChoices>('/api/ecosystems')));
  }

  templates(): Promise<Template[]> {
    return (this.templateList ??= firstValueFrom(this.http.get<Template[]>('/api/templates')));
  }

  lists(): Promise<PackageList[]> {
    return firstValueFrom(this.http.get<PackageList[]>('/api/lists'));
  }

  list(id: number): Promise<PackageList> {
    return firstValueFrom(this.http.get<PackageList>(`/api/lists/${id}`));
  }

  createList(input: ListInput): Promise<PackageList> {
    return firstValueFrom(this.http.post<PackageList>('/api/lists', input));
  }

  updateList(id: number, input: ListInput): Promise<PackageList> {
    return firstValueFrom(this.http.put<PackageList>(`/api/lists/${id}`, input));
  }

  deleteList(id: number): Promise<unknown> {
    return firstValueFrom(this.http.delete(`/api/lists/${id}`));
  }

  resolve(id: number): Promise<Job> {
    return firstValueFrom(this.http.post<Job>(`/api/lists/${id}/resolve`, {}));
  }

  listArtifacts(id: number): Promise<Artifact[]> {
    return firstValueFrom(this.http.get<Artifact[]>(`/api/lists/${id}/artifacts`));
  }

  artifacts(filters: { ecosystem?: string; q?: string; pending?: boolean; page?: number }): Promise<Page<Artifact>> {
    let params = new HttpParams();
    if (filters.ecosystem) params = params.set('ecosystem', filters.ecosystem);
    if (filters.q) params = params.set('q', filters.q);
    if (filters.pending) params = params.set('pending', '1');
    if (filters.page) params = params.set('page', filters.page);
    return firstValueFrom(this.http.get<Page<Artifact>>('/api/artifacts', { params }));
  }

  exports(): Promise<Exports> {
    return firstValueFrom(this.http.get<Exports>('/api/exports'));
  }

  createExport(full: boolean, maxPartMiB: number | null): Promise<Job> {
    return firstValueFrom(this.http.post<Job>('/api/exports', { full, maxPartMiB }));
  }

  deleteExportFiles(id: number): Promise<Export> {
    return firstValueFrom(this.http.delete<Export>(`/api/exports/${id}/files`));
  }

  search(ecosystem: EcosystemId, q: string): Promise<SearchResult[]> {
    return firstValueFrom(this.http.get<SearchResult[]>('/api/search', { params: { ecosystem, q } }));
  }

  versions(ecosystem: EcosystemId, name: string): Promise<{ versions: string[]; latest: string | null }> {
    return firstValueFrom(this.http.get<{ versions: string[]; latest: string | null }>('/api/search/versions', { params: { ecosystem, name } }));
  }

  /** Aperçu d'un motif : paquets correspondants avec leur contrainte */
  expand(ecosystem: EcosystemId, line: string): Promise<{ lines: string[]; skipped: string[]; pattern: boolean }> {
    return firstValueFrom(this.http.post<{ lines: string[]; skipped: string[]; pattern: boolean }>('/api/search/expand', { ecosystem, line }));
  }

  security(): Promise<SecurityReport> {
    return firstValueFrom(this.http.get<SecurityReport>('/api/security'));
  }

  audit(listId?: number): Promise<Job> {
    return firstValueFrom(this.http.post<Job>('/api/security/audit', listId ? { listId } : {}));
  }

  settings(): Promise<{ identity: Identity; exportDir: string; storageDir: string }> {
    return firstValueFrom(this.http.get<{ identity: Identity; exportDir: string; storageDir: string }>('/api/settings'));
  }

  saveSettings(name: string): Promise<{ identity: Identity; exportDir: string; storageDir: string }> {
    return firstValueFrom(this.http.put<{ identity: Identity; exportDir: string; storageDir: string }>('/api/settings', { name }));
  }

  blacklist(): Promise<Blacklist> {
    return firstValueFrom(this.http.get<Blacklist>('/api/blacklist'));
  }

  addBlacklist(input: BlacklistInput): Promise<BlacklistEntry> {
    return firstValueFrom(this.http.post<BlacklistEntry>('/api/blacklist', input));
  }

  removeBlacklist(id: number): Promise<unknown> {
    return firstValueFrom(this.http.delete(`/api/blacklist/${id}`));
  }

  sources(): Promise<Source[]> {
    return firstValueFrom(this.http.get<Source[]>('/api/sources'));
  }

  saveSource(input: SourceInput): Promise<Source> {
    return firstValueFrom(input.id ? this.http.put<Source>(`/api/sources/${input.id}`, input) : this.http.post<Source>('/api/sources', input));
  }

  deleteSource(id: number): Promise<unknown> {
    return firstValueFrom(this.http.delete(`/api/sources/${id}`));
  }

  testSource(input: SourceInput): Promise<{ ok: boolean; message: string }> {
    return firstValueFrom(this.http.post<{ ok: boolean; message: string }>('/api/sources/test', input));
  }
}
