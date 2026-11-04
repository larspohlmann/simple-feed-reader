import { EntriesPage, EntryDto } from '../models';
import { TrendingWindow } from './trending.models';

/** `GET /api/trending` (#2017): each story is its lead's list row, the other outlets in its `duplicates`. */
export interface TrendingResponseDto {
  window: TrendingWindow;
  builtAt: string | null;
  stories: EntryDto[];
}

/** The Trending list as the reader's list store holds any list: one row per story. */
export function trendingPage(response: TrendingResponseDto): EntriesPage {
  return { entries: response.stories, nextCursor: null };
}
