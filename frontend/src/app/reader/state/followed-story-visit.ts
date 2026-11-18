import { Injectable, computed, inject } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntryDto } from '../models';
import { STORY_ENTRIES } from './story-entries';
import { LandedList } from './landed-list';
import { FollowedStoriesStore } from './followed-stories.store';
import { sameMembers } from './same-members';

const NONE_NEW: ReadonlySet<number> = new Set();

/** A visit to a followed Trending story's list (#2128): it begins at the story's "last opened" its first page
 *  carries, records the one "opened", and ends when any other list lands or any list fails to load. */
@Injectable({ providedIn: 'root' })
export class FollowedStoryVisit {
  private readonly storyList = inject(STORY_ENTRIES);
  private readonly followedStories = inject(FollowedStoriesStore);
  /** `openedAt` is held for the visit: the record it posts moves the server's "last opened" past the rows it marks. */
  private readonly visit = accountSignal<{ storyId: number; openedAt: string } | null>(null);
  /** The loaded rows that joined the story since the visit began. */
  readonly newSinceVisitIds = computed(() => this.newAmong(this.storyList.entries()), {
    equal: sameMembers,
  });

  constructor() {
    this.storyList.listLanded.subscribe((list) => this.landed(list));
  }

  private landed({ query, page }: LandedList): void {
    const storyId = query.storyId;
    if (
      storyId == null ||
      page === null ||
      !('lastOpenedAt' in page) ||
      typeof page.lastOpenedAt !== 'string'
    ) {
      this.visit.set(null);
      return;
    }
    if (this.visit()?.storyId === storyId) return;
    this.visit.set({ storyId, openedAt: page.lastOpenedAt });
    this.followedStories.markOpened(storyId);
  }

  private newAmong(entries: EntryDto[]): ReadonlySet<number> {
    const visit = this.visit();
    if (visit === null) return NONE_NEW;
    const since = Date.parse(visit.openedAt);
    return new Set(
      entries
        .filter((entry) => entry.joinedAt !== undefined && Date.parse(entry.joinedAt) > since)
        .map((entry) => entry.id),
    );
  }
}
