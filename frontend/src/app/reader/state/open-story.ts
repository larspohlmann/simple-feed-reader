import { Injectable, inject } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntriesStore } from './entries.store';
import { LandedList } from './landed-list';

interface LandedStory {
  readonly id: number;
  readonly title: string | null;
  readonly followed: boolean;
}

/** What the last story page that landed said of its story (#2329): its title, and whether the reader follows it. */
@Injectable({ providedIn: 'root' })
export class OpenStory {
  private readonly entries = inject(EntriesStore);
  private readonly landedStory = accountSignal<LandedStory | null>(null);

  constructor() {
    this.entries.listLanded.subscribe((list) => this.landed(list));
  }

  titleOf(id: number): string | null {
    return this.storyOf(id)?.title ?? null;
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
      followed: typeof page.lastOpenedAt === 'string',
    });
  }
}
