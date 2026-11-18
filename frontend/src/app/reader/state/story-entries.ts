import { InjectionToken, inject } from '@angular/core';
import { EntriesStore } from './entries.store';

/** The open Trending story's list (#2329): a second `EntriesStore`, linked to the list it opens over, so that
 *  list keeps its rows and its place while a row's state reads the same in both. */
export const STORY_ENTRIES = new InjectionToken<EntriesStore>('STORY_ENTRIES', {
  providedIn: 'root',
  factory: () => {
    const story = new EntriesStore();
    inject(EntriesStore).link(story);
    return story;
  },
});
