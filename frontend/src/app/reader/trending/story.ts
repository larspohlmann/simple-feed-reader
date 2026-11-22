import { EntryDto } from '../models';
import { TrendingStoryDto } from './trending.models';

/** The glyph that marks a Trending story wherever a card names one. */
export const STORY_ICON = 'trending_up';

export function storyTitleId(storyId: number): string {
  return `story-title-${storyId}`;
}

export function storySynopsisId(storyId: number): string {
  return `story-synopsis-${storyId}`;
}

/** The router link to a story's list, followed or not (#2128, #2329). */
export function storyListLink(storyId: number): [string, number] {
  return ['/trending/stories', storyId];
}

/** Merged into the current query, so a story opens over the current list but closes an open article (#2479). */
export const STORY_LIST_QUERY_PARAMS = { entry: null, link: null } as const;

/** A Trending story is viewed once any of its entries was opened: by the server's word, which counts
 *  entries the card does not show, or because one of its articles was opened here (#2017). */
export function isStoryViewed(story: TrendingStoryDto): boolean {
  return story.viewed || story.articles.some((article) => article.isViewed);
}

/** Whether a card shows the unread dot: an ordinary row by its read state, a Trending lead by its story. */
export function showsUnreadMark(entry: EntryDto, story: TrendingStoryDto | null): boolean {
  return story ? !isStoryViewed(story) : !entry.isHidden;
}
