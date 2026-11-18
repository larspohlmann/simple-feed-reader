import { Injectable, computed, inject } from '@angular/core';
import { EMPTY, Observable, defer, finalize, tap } from 'rxjs';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { FollowedStoryDto, TrendingStoryDto } from '../trending/trending.models';
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

  private readonly answered = accountSignal(false);
  /** A follow or unfollow on the wire, or one the server took that no load has answered since. */
  private readonly intents = accountSignal<ReadonlyMap<number, boolean>>(new Map());
  private readonly sending = new Set<number>();

  private readonly loads = new StaleGatedLoad();
  /** Bumped by every change made here: a load sent before one predates it, so its answer is dropped. */
  private localChanges = 0;
  private nextBuildDueAt: number | null = null;

  constructor() {
    onIdentityChange(() => {
      this.loads.forget();
      this.sending.clear();
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
      this.answered.set(true);
      this.intents.update((intents) => {
        const sent = new Map([...intents].filter(([id]) => this.sending.has(id)));
        return sent.size === intents.size ? intents : sent;
      });
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

  isFollowed(story: Pick<TrendingStoryDto, 'id' | 'followed'>): boolean {
    const intent = this.intents().get(story.id);
    if (intent !== undefined) return intent;
    return this.answered() ? this.storiesById().has(story.id) : story.followed;
  }

  /** Sends on subscribe, and sends nothing while a change to the same story is still on the wire. */
  setFollowing(storyId: number, following: boolean): Observable<void> {
    return defer(() => {
      if (this.sending.has(storyId)) return EMPTY;
      this.sending.add(storyId);
      this.localChanges++;
      this.intents.update((intents) => new Map(intents).set(storyId, following));
      const request = following ? this.api.follow(storyId) : this.api.unfollow(storyId);
      return request.pipe(
        tap({
          next: () => this.adoptChange(storyId, following),
          error: () => this.dropIntent(storyId),
        }),
        finalize(() => this.sending.delete(storyId)),
      );
    });
  }

  unfollow(id: number): void {
    this.setFollowing(id, false).subscribe({ error: () => undefined });
  }

  private adoptChange(storyId: number, following: boolean): void {
    this.sending.delete(storyId);
    this.localChanges++;
    if (following) this.load();
    else this.followed.update((stories) => stories.filter((story) => story.id !== storyId));
  }

  private dropIntent(storyId: number): void {
    this.intents.update((intents) => {
      const remaining = new Map(intents);
      remaining.delete(storyId);
      return remaining;
    });
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
