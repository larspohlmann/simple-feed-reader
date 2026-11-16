import type { EntriesPage } from '../models';

export type TrendingWindow = 'day' | 'week';

/** The sidebar badge: the stories of one window the reader has not opened (#2017). */
export interface TrendingUnopened {
  window: TrendingWindow;
  unopened: number;
}

/** What a Trending row stands for beyond its lead (#2017). */
export interface TrendingStory {
  /** Stable across builds (#2128): a hide and a follow name the story by it. */
  id: number;
  followed: boolean;
  /** The reader's sources: the article feeds they follow and the accounts whose posts share a card (#2229). */
  outletCount: number;
  /** The server's verdict: some entry of the story, a social one included, was opened. */
  viewed: boolean;
  /** Posts sharing the story's articles, each post once. */
  shareCount: number;
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

/** A followed Trending story's page (#2128): `newEntryIds` are its rows whose `joinedAt` is after `lastOpenedAt`. */
export interface FollowedStoryPageDto extends EntriesPage {
  lastOpenedAt: string;
  newEntryIds: number[];
}
