import { Injectable, computed, inject } from '@angular/core';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { FollowedStoryDto } from '../trending/trending.models';
import { TrendingApi } from '../trending/trending-api';
import { StaleGatedLoad } from './stale-gated-load';

/** The Trending stories the reader follows (#2128), in the order the server ranks them. */
@Injectable({ providedIn: 'root' })
export class FollowedStoriesStore {
  private readonly api = inject(TrendingApi);

  private readonly followed = accountSignal<FollowedStoryDto[]>([], { equal: sameFollowedStories });
  readonly stories = this.followed.asReadonly();
  private readonly storiesById = computed(
    () => new Map(this.followed().map((story) => [story.id, story])),
  );

  private readonly loads = new StaleGatedLoad();
  /** Bumped by every change made here: a load sent before one predates it, so its answer is dropped. */
  private localChanges = 0;
  private nextBuildDueAt: number | null = null;

  constructor() {
    onIdentityChange(() => {
      this.loads.forget();
      this.nextBuildDueAt = null;
    });
  }

  byId(id: number): FollowedStoryDto | null {
    return this.storiesById().get(id) ?? null;
  }

  /** An answer overtaken by a change made here asks again, so the list never waits a poll for the server's word. */
  load(): void {
    const changesWhenSent = this.localChanges;
    this.loads.run(this.api.followed(), (response) => {
      if (this.localChanges !== changesWhenSent) {
        this.load();
        return;
      }
      this.followed.set(response.stories);
      this.nextBuildDueAt =
        response.nextBuildDueAt === null ? null : Date.parse(response.nextBuildDueAt);
    });
  }

  reloadIfStale(): void {
    if (this.loads.isStale() && this.buildIsDue()) this.load();
  }

  private buildIsDue(): boolean {
    return this.nextBuildDueAt === null || Date.now() >= this.nextBuildDueAt;
  }

  unfollow(id: number, onSuccess?: () => void): void {
    this.api.unfollow(id).subscribe({
      next: () => {
        this.removeUnfollowed(id);
        onSuccess?.();
      },
      error: () => undefined,
    });
  }

  /** Drops a story the reader unfollowed elsewhere, as on its Trending card. */
  removeUnfollowed(id: number): void {
    this.localChanges++;
    this.followed.update((stories) => stories.filter((story) => story.id !== id));
  }

  /** Zero at once; a failed POST keeps the zero and the next load puts the server's count back. The caller has
   *  just been served the story, so it posts even before the stories have loaded. A load sent before the POST
   *  answered counted without it, so the answer counts as a change too. */
  markOpened(id: number): void {
    this.localChanges++;
    this.followed.update((stories) =>
      stories.map((story) => (story.id === id ? { ...story, newCount: 0 } : story)),
    );
    this.api.opened(id).subscribe({ next: () => this.localChanges++, error: () => undefined });
  }
}

function sameFollowedStories(left: FollowedStoryDto[], right: FollowedStoryDto[]): boolean {
  return (
    left.length === right.length && left.every((story, index) => sameStory(story, right[index]))
  );
}

function sameStory(left: FollowedStoryDto, right: FollowedStoryDto): boolean {
  return (
    left.id === right.id &&
    left.headline === right.headline &&
    left.newCount === right.newCount &&
    left.quiet === right.quiet &&
    left.lastJoinedAt === right.lastJoinedAt
  );
}
