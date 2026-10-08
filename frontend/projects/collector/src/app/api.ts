import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { EcosystemId, Job } from '@sas/shared';
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
  updatedAt: string;
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
  freeSpace: number | null;
  pending: Pending;
  full: Pending;
  items: Export[];
}

export interface Overview {
  identity: Identity;
  ecosystems: Partial<Record<EcosystemId, { artifacts: number; size: number; pending: number }>>;
  lists: number;
  storage: { blobs: number; size: number; free: number | null };
  lastExport: Export | null;
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

  settings(): Promise<{ identity: Identity; exportDir: string; storageDir: string }> {
    return firstValueFrom(this.http.get<{ identity: Identity; exportDir: string; storageDir: string }>('/api/settings'));
  }

  saveSettings(name: string): Promise<{ identity: Identity; exportDir: string; storageDir: string }> {
    return firstValueFrom(this.http.put<{ identity: Identity; exportDir: string; storageDir: string }>('/api/settings', { name }));
  }
}
