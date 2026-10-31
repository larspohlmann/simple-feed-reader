import { HttpClient } from '@angular/common/http';
import { Injectable, NgZone, inject, signal } from '@angular/core';
import { API_BASE_URL } from './api';
import { onTabVisibilityChange } from './tab-visibility';

const RECHECK_AFTER_MS = 30 * 60_000;

/** Which build is running: the tag it was cut from, the commit, and when. */
export interface ReleaseVersion {
  version: string;
  commit: string;
  builtAt: string;
}

/** The newest release upstream: its tag and the page that holds its notes. */
export interface LatestRelease {
  version: string;
  notesUrl: string;
}

/** The /api/version payload: the running build plus the upstream update check.
 *  The update fields are optional so a bundle newer than the server it happens
 *  to reach still parses — a missing field just means "no update". */
interface VersionPayload extends ReleaseVersion {
  latest?: LatestRelease | null;
  updateAvailable?: boolean;
}

@Injectable({ providedIn: 'root' })
export class VersionService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly zone = inject(NgZone);
  private lastLoadedAt: number | null = null;

  readonly apiVersion = signal<ReleaseVersion | null>(null);
  /** The endpoint could not be reached. The baked-in app version still stands. */
  readonly unavailable = signal(false);

  /** The newest release upstream, or null when there is none to report. */
  readonly latest = signal<LatestRelease | null>(null);
  /** True when that release is worth updating to. The sidebar badge keys on it. */
  readonly updateAvailable = signal(false);

  constructor() {
    onTabVisibilityChange((hidden) => {
      if (!hidden && this.lastLoadedAt !== null) this.zone.run(() => this.loadIfStale());
    });
  }

  /**
   * Re-fetched on every call rather than cached: the server's build can change
   * under a bundle that stays loaded — that is exactly the case worth catching.
   */
  load(): void {
    this.lastLoadedAt = Date.now();
    this.http.get<VersionPayload>(`${this.base}/api/version`).subscribe({
      next: (payload) => {
        this.apiVersion.set(payload);
        this.unavailable.set(false);
        this.latest.set(payload.latest ?? null);
        this.updateAvailable.set(payload.updateAvailable ?? false);
      },
      error: () => {
        this.apiVersion.set(null);
        this.unavailable.set(true);
        this.latest.set(null);
        this.updateAvailable.set(false);
      },
    });
  }

  loadIfStale(): void {
    const fresh = this.lastLoadedAt !== null && Date.now() - this.lastLoadedAt < RECHECK_AFTER_MS;
    if (fresh && !this.unavailable()) return;
    this.load();
  }
}
