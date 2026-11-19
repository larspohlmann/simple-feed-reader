import { Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { accountSignal } from '../../core/auth/session-identity';
import { ListContext } from './list-context';
import { LandedList } from './landed-list';

/** How many rows a story's list holds, and how many of them are unread. */
export interface StoryTally {
  readonly memberCount: number;
  readonly unreadCount: number;
}

interface LandedStory {
  readonly id: number;
  readonly title: string | null;
  readonly synopsis: string | null;
  readonly followed: boolean;
  readonly tally: StoryTally;
}

/** What the last story page its host's list landed said of its story (#2329): its title, synopsis, tally and
 *  whether the reader follows it. */
@Injectable()
export class OpenStory {
  private readonly landedStory = accountSignal<LandedStory | null>(null);

  constructor() {
    inject(ListContext)
      .entries.listLanded.pipe(takeUntilDestroyed())
      .subscribe((list) => this.landed(list));
  }

  titleOf(id: number): string | null {
    return this.storyOf(id)?.title ?? null;
  }

  synopsisOf(id: number): string | null {
    return this.storyOf(id)?.synopsis ?? null;
  }

  tallyOf(id: number): StoryTally | null {
    return this.storyOf(id)?.tally ?? null;
  }

  followedOnServer(id: number): boolean {
    return this.storyOf(id)?.followed ?? false;
  }

  private storyOf(id: number): LandedStory | null {
    const story = this.landedStory();
    return story?.id === id ? story : null;
  }

  private landed({ query, page }: LandedList): void {
    if (query.storyId == null || page === null) {
      this.landedStory.set(null);
      return;
    }
    this.landedStory.set({
      id: query.storyId,
      title: page.title ?? null,
      synopsis: page.synopsis ?? null,
      followed: typeof page.lastOpenedAt === 'string',
      tally: { memberCount: page.memberCount ?? 0, unreadCount: page.unreadCount ?? 0 },
    });
  }
}
