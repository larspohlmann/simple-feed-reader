import { Injectable, computed, inject, signal } from '@angular/core';
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

  /** Settled here since the last load was sent: a response already out predates them, so it must not undo them. */
  private readonly zeroedSinceLoad = signal<ReadonlySet<number>>(new Set());
  private readonly removedSinceLoad = signal<ReadonlySet<number>>(new Set());

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
    const zeroedWhenSent = this.zeroedSinceLoad();
    const removedWhenSent = this.removedSinceLoad();
    const request = this.api.followed().subscribe({
      next: (response) => {
        this.inFlight = null;
        this.followed.set(this.withLocalChanges(response.stories));
        this.zeroedSinceLoad.update((ids) => withoutAll(ids, zeroedWhenSent));
        this.removedSinceLoad.update((ids) => withoutAll(ids, removedWhenSent));
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
        this.removedSinceLoad.update((ids) => new Set(ids).add(id));
        this.followed.update((stories) => stories.filter((story) => story.id !== id));
        onSuccess?.();
      },
      error: () => undefined,
    });
  }

  /** Zero at once; a failed POST keeps the zero and the next load puts the server's count back. The caller has
   *  just been served the story, so it posts even before the stories have loaded. */
  markOpened(id: number): void {
    this.zeroedSinceLoad.update((ids) => new Set(ids).add(id));
    this.followed.update((stories) =>
      stories.map((story) => (story.id === id ? { ...story, newCount: 0 } : story)),
    );
    this.api.opened(id).subscribe({ error: () => undefined });
  }

  private withLocalChanges(stories: FollowedStoryDto[]): FollowedStoryDto[] {
    const zeroed = this.zeroedSinceLoad();
    const removed = this.removedSinceLoad();
    return stories
      .filter((story) => !removed.has(story.id))
      .map((story) => (zeroed.has(story.id) ? { ...story, newCount: 0 } : story));
  }

  private forgetLoad(): void {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.lastLoadedAt = 0;
    this.zeroedSinceLoad.set(new Set());
    this.removedSinceLoad.set(new Set());
  }
}

function withoutAll(ids: ReadonlySet<number>, spent: ReadonlySet<number>): ReadonlySet<number> {
  return new Set([...ids].filter((id) => !spent.has(id)));
}
