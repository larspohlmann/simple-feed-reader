import { inject } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntriesPage, EntryDto, EntryQuery } from '../models';
import { FollowedStoryPageDto } from '../trending/trending.models';
import { FollowedStoriesStore } from './followed-stories.store';

/** A visit to a followed Trending story's list (#2128): it begins at the story's "last opened" its first page
 *  carries, and records the one "opened". Built in a field initializer, so it injects there. */
export class StoryVisit {
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly visitedStoryId = accountSignal<number | null>(null);
  /** Held for the visit: the record it posts moves the server's "last opened" past the rows it marks. */
  private readonly visitOpenedAt = accountSignal<string | null>(null);

  landed(query: EntryQuery, page: EntriesPage | FollowedStoryPageDto): void {
    const storyId = query.followedStoryId;
    if (storyId == null || !('lastOpenedAt' in page)) {
      this.visitedStoryId.set(null);
      this.visitOpenedAt.set(null);
      return;
    }
    if (this.visitedStoryId() === storyId) return;
    this.visitedStoryId.set(storyId);
    this.visitOpenedAt.set(page.lastOpenedAt);
    this.followedStories.markOpened(storyId);
  }

  /** The rows that joined the story since the visit began. */
  newAmong(entries: EntryDto[]): ReadonlySet<number> {
    const openedAt = this.visitOpenedAt();
    if (openedAt === null) return new Set();
    const since = Date.parse(openedAt);
    return new Set(
      entries
        .filter((entry) => entry.joinedAt !== undefined && Date.parse(entry.joinedAt) > since)
        .map((entry) => entry.id),
    );
  }
}
