import { inject } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntriesPage, EntryQuery, FollowedStoryPageDto } from '../models';
import { FollowedStoriesStore } from './followed-stories.store';

const NOTHING_NEW: ReadonlySet<number> = new Set();

/** A visit to a followed Trending story's list (#2128): what joined since the reader last opened the story, and
 *  the one "opened" the visit records. Built in a field initializer, so it injects there. */
export class StoryVisit {
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly marked = accountSignal<ReadonlySet<number>>(NOTHING_NEW);
  private readonly visitedStoryId = accountSignal<number | null>(null);
  readonly newSinceVisitIds = this.marked.asReadonly();

  /** A load of another list ends the visit, so coming back to the story is a new one. */
  starting(query: EntryQuery): void {
    if (query.followedStoryId !== this.visitedStoryId()) this.visitedStoryId.set(null);
  }

  /** The visit's first page records it; a reload within the visit keeps its marks, since the record moved
   *  the server's "last opened" past them. */
  landed(query: EntryQuery, page: EntriesPage | FollowedStoryPageDto): void {
    const storyId = query.followedStoryId;
    if (storyId == null || !('newEntryIds' in page)) {
      this.marked.set(NOTHING_NEW);
      return;
    }
    if (this.visitedStoryId() === storyId) {
      this.marked.update((marked) => new Set([...marked, ...page.newEntryIds]));
      return;
    }
    this.visitedStoryId.set(storyId);
    this.marked.set(new Set(page.newEntryIds));
    this.followedStories.markOpened(storyId);
  }

  failed(): void {
    this.marked.set(NOTHING_NEW);
  }
}
