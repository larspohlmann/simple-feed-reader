import type { EntriesPage, EntryDto } from '../models';

export type TrendingWindow = 'day' | 'week';

/** The sidebar badge: the stories of one window the reader has not opened (#2017). */
export interface TrendingUnopened {
  window: TrendingWindow;
  unopened: number;
}

/** A Trending article: its list row, and the posts that share it. */
export type StoryArticleDto = EntryDto & { shares: EntryDto[] };

/** A Trending story (#2017, #2328): its articles, the lead first. */
export interface TrendingStoryDto {
  /** Stable across builds (#2128): a hide and a follow name the story by it. */
  id: number;
  /** The event the story's articles report, as a one-line headline (#2329). */
  title: string;
  followed: boolean;
  /** The reader's sources: the article feeds they follow and the accounts whose posts share a card (#2229). */
  outletCount: number;
  /** The server's verdict: some entry of the story, a social one included, was opened. */
  viewed: boolean;
  /** Posts sharing the story's articles, each post once. */
  shareCount: number;
  leadEntryId: number;
  articles: StoryArticleDto[];
}

/** A story the reader follows, as the sidebar lists it (#2128). */
export interface FollowedStoryDto {
  id: number;
  headline: string | null;
  /** Members that joined since the reader last opened the story. */
  newCount: number;
  quiet: boolean;
  lastJoinedAt: string;
}

export interface FollowedStoriesResponseDto {
  stories: FollowedStoryDto[];
  nextBuildDueAt: string | null;
}

/** A Trending story's page (#2128, #2329): `newEntryIds` are its rows whose `joinedAt` is after `lastOpenedAt`,
 *  which is null for a story the reader does not follow. */
export interface StoryPageDto extends EntriesPage {
  title: string | null;
  /** The rows the story's list holds whichever page it is, and the unread ones among them (#2329). */
  memberCount: number;
  unreadCount: number;
  lastOpenedAt: string | null;
  /** Kept for other clients such as iOS; the web client derives its marks from `lastOpenedAt`. */
  newEntryIds: number[];
}
