import { Injectable, computed, inject } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntryDto } from '../models';
import { EntriesStore } from './entries.store';
import { LandedList } from './landed-list';
import { FollowedStoriesStore } from './followed-stories.store';

const NONE_NEW: ReadonlySet<number> = Object.freeze(new Set<number>());

/** A visit to a followed Trending story's list (#2128): it begins at the story's "last opened" its first page
 *  carries, records the one "opened", and ends when any other list lands or any list fails to load. */
@Injectable({ providedIn: 'root' })
export class FollowedStoryVisit {
  private readonly entries = inject(EntriesStore);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly visitedStoryId = accountSignal<number | null>(null);
  /** Held for the visit: the record it posts moves the server's "last opened" past the rows it marks. */
  private readonly visitOpenedAt = accountSignal<string | null>(null);
  /** The loaded rows that joined the story since the visit began. */
  readonly newSinceVisitIds = computed(() => this.newAmong(this.entries.entries()));

  constructor() {
    this.entries.listLanded.subscribe((list) => this.landed(list));
  }

  private landed({ query, page }: LandedList): void {
    const storyId = query.followedStoryId;
    if (storyId == null || page === null || !('lastOpenedAt' in page)) {
      this.visitedStoryId.set(null);
      this.visitOpenedAt.set(null);
      return;
    }
    if (this.visitedStoryId() === storyId) return;
    this.visitedStoryId.set(storyId);
    this.visitOpenedAt.set(page.lastOpenedAt);
    this.followedStories.markOpened(storyId);
  }

  private newAmong(entries: EntryDto[]): ReadonlySet<number> {
    const openedAt = this.visitOpenedAt();
    if (openedAt === null) return NONE_NEW;
    const since = Date.parse(openedAt);
    return new Set(
      entries
        .filter((entry) => entry.joinedAt !== undefined && Date.parse(entry.joinedAt) > since)
        .map((entry) => entry.id),
    );
  }
}
