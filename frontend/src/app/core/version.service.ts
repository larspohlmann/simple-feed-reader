// src/app/core/version.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { API_BASE_URL } from './api';

/** Which build is running: the tag it was cut from, the commit, and when. */
export interface ReleaseVersion {
  version: string;
  commit: string;
  builtAt: string;
}

@Injectable({ providedIn: 'root' })
export class VersionService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  readonly apiVersion = signal<ReleaseVersion | null>(null);
  /** The endpoint could not be reached. The baked-in app version still stands. */
  readonly unavailable = signal(false);

  /**
   * Re-fetched on every call rather than cached: the server's build can change
   * under a bundle that stays loaded — that is exactly the case worth catching.
   */
  load(): void {
    this.http.get<ReleaseVersion>(`${this.base}/api/version`).subscribe({
      next: (release) => {
        this.apiVersion.set(release);
        this.unavailable.set(false);
      },
      error: () => {
        this.apiVersion.set(null);
        this.unavailable.set(true);
      },
    });
  }
}
