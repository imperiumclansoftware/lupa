import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { EcosystemId, Job } from '@sas/shared';
import { firstValueFrom } from 'rxjs';

export interface ImportedBundle {
  id: number;
  uuid: string;
  collectorId: string;
  collectorName: string;
  sequence: number;
  full: boolean;
  source: string;
  artifactCount: number;
  newBlobCount: number;
  size: number;
  summary: Partial<Record<EcosystemId, number>>;
  createdAt: string;
  importedAt: string;
}

export interface Overview {
  ecosystems: Partial<Record<EcosystemId, { packages: number; files: number; size: number }>>;
  storage: { size: number; free: number | null };
  baseUrl: string;
  lastImport: ImportedBundle | null;
  trustedKeys: number;
  activeJobs: Job[];
  recentJobs: Job[];
}

export interface BundleReport {
  id: string;
  collector: { id: string; name: string; fingerprint: string | null; trusted: boolean };
  signatureValid: boolean;
  sequence: number;
  full: boolean;
  createdAt: string;
  artifactCount: number;
  blobCount: number;
  size: number;
  summary: Partial<Record<EcosystemId, number>>;
  lastImportedSequence: number | null;
  missingSequences: number[];
  missingArtifacts: string[];
  missingArtifactCount: number;
  alreadyImported: boolean;
  importable: boolean;
}

export interface InboxItem {
  source: 'usb' | 'upload';
  name: string;
  parts: string[];
  size: number;
  missingParts: number[];
  modifiedAt: string;
  report: BundleReport | null;
  error: string | null;
}

export interface Inbox {
  sources: { id: string; path: string; available: boolean }[];
  items: InboxItem[];
}

export interface PackageSummary {
  ecosystem: EcosystemId;
  name: string;
  versions: number;
  files: number;
  size: number;
  updatedAt: string;
}

export interface PackageVersion {
  id: number;
  version: string;
  filename: string | null;
  size: number;
  detail: string | null;
  digest: string | null;
  bundleSequence: number | null;
  createdAt: string;
  updatedAt: string;
}

export interface PackageDetail {
  ecosystem: EcosystemId;
  name: string;
  description: string | null;
  items: PackageVersion[];
}

export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  pageSize: number;
}

export interface TrustedKey {
  id: number;
  label: string;
  publicKey: string;
  fingerprint: string;
  createdAt: string;
}

@Injectable({ providedIn: 'root' })
export class MirrorApi {
  private readonly http = inject(HttpClient);

  overview(): Promise<Overview> {
    return firstValueFrom(this.http.get<Overview>('/api/overview'));
  }

  jobs(): Promise<Job[]> {
    return firstValueFrom(this.http.get<Job[]>('/api/jobs'));
  }

  inbox(): Promise<Inbox> {
    return firstValueFrom(this.http.get<Inbox>('/api/inbox'));
  }

  importBundle(source: string, name: string, trust?: { fingerprint: string }): Promise<Job> {
    return firstValueFrom(this.http.post<Job>('/api/inbox/import', { source, name, trust: !!trust, fingerprint: trust?.fingerprint }));
  }

  deleteUpload(name: string): Promise<unknown> {
    return firstValueFrom(this.http.delete(`/api/inbox/upload/${encodeURIComponent(name)}`));
  }

  imports(): Promise<ImportedBundle[]> {
    return firstValueFrom(this.http.get<ImportedBundle[]>('/api/imports'));
  }

  packages(filters: { ecosystem?: string; q?: string; page?: number }): Promise<Page<PackageSummary>> {
    let params = new HttpParams();
    if (filters.ecosystem) params = params.set('ecosystem', filters.ecosystem);
    if (filters.q) params = params.set('q', filters.q);
    if (filters.page) params = params.set('page', filters.page);
    return firstValueFrom(this.http.get<Page<PackageSummary>>('/api/packages', { params }));
  }

  package(ecosystem: string, name: string): Promise<PackageDetail> {
    return firstValueFrom(this.http.get<PackageDetail>(`/api/packages/${ecosystem}/${name}`));
  }

  keys(): Promise<TrustedKey[]> {
    return firstValueFrom(this.http.get<TrustedKey[]>('/api/keys'));
  }

  addKey(publicKey: string, label: string): Promise<TrustedKey> {
    return firstValueFrom(this.http.post<TrustedKey>('/api/keys', { publicKey, label }));
  }

  deleteKey(id: number): Promise<unknown> {
    return firstValueFrom(this.http.delete(`/api/keys/${id}`));
  }

  /** Envoi par morceaux de 16 Mio, avec reprise ; progress reçoit les octets envoyés. */
  async upload(file: File, progress: (sent: number) => void, signal: AbortSignal): Promise<void> {
    const chunk = 16 * 2 ** 20;
    const start = await firstValueFrom(this.http.post<{ id: string; received: number }>('/api/uploads', { filename: file.name, size: file.size }));
    let offset = start.received;
    progress(offset);
    while (offset < file.size) {
      if (signal.aborted) throw new DOMException('Envoi annulé', 'AbortError');
      const body = file.slice(offset, Math.min(offset + chunk, file.size));
      const res = await firstValueFrom(
        this.http.put<{ received: number }>(`/api/uploads/${start.id}`, body, {
          params: { offset },
          headers: { 'Content-Type': 'application/octet-stream' },
        }),
      );
      offset = res.received;
      progress(offset);
    }
    await firstValueFrom(this.http.post(`/api/uploads/${start.id}/complete`, {}));
  }
}
