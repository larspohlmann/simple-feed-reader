import { Injectable, computed, inject } from '@angular/core';
import { Subscription } from 'rxjs';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { FollowedStoryDto } from '../trending/trending.models';
import { TrendingApi } from '../trending/trending-api';
import { countsAreStale } from './sidebar-freshness';

/** The Trending stories the reader follows (#2128), in the order the server ranks them. */
@Injectable({ providedIn: 'root' })
export class FollowedStoriesStore {
  private readonly api = inject(TrendingApi);

  private readonly followed = accountSignal<FollowedStoryDto[]>([]);
  readonly stories = this.followed.asReadonly();
  private readonly storiesById = computed(
    () => new Map(this.followed().map((story) => [story.id, story])),
  );

  private lastLoadedAt = 0;
  private inFlight: Subscription | null = null;

  constructor() {
    onIdentityChange(() => this.forgetLoad());
  }

  byId(id: number): FollowedStoryDto | null {
    return this.storiesById().get(id) ?? null;
  }

  load(): void {
    this.lastLoadedAt = Date.now();
    this.inFlight?.unsubscribe();
    const request = this.api.followed().subscribe({
      next: (response) => {
        this.inFlight = null;
        this.followed.set(response.stories);
      },
      error: () => (this.inFlight = null),
    });
    this.inFlight = request.closed ? null : request;
  }

  reloadIfStale(): void {
    if (this.inFlight) return;
    if (!countsAreStale(this.lastLoadedAt)) return;
    this.load();
  }

  unfollow(id: number, onSuccess?: () => void): void {
    this.api.unfollow(id).subscribe({
      next: () => {
        this.followed.update((stories) => stories.filter((story) => story.id !== id));
        onSuccess?.();
      },
      error: () => undefined,
    });
  }

  /** Zero at once; a failed POST keeps the zero and the next load puts the server's count back. */
  markOpened(id: number): void {
    if (!this.storiesById().has(id)) return;
    this.followed.update((stories) =>
      stories.map((story) => (story.id === id ? { ...story, newCount: 0 } : story)),
    );
    this.api.opened(id).subscribe({ error: () => undefined });
  }

  private forgetLoad(): void {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.lastLoadedAt = 0;
  }
}
