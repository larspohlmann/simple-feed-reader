import { inject, Injectable } from '@angular/core';
import { EntryDto } from '../models';
import { EntriesStore } from '../state/entries.store';

/** Follow on a Trending card (#2128): a toggle, shown followed at once and put back if the request fails. */
@Injectable()
export class TrendingFollowAction {
  private readonly entries = inject(EntriesStore);

  run(entry: EntryDto): void {
    this.entries.setStoryFollowed(entry.id, !entry.story?.followed);
  }
}
