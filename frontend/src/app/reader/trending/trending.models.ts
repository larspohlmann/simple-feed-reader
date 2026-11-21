import type { EntriesPage, EntryDto } from '../models';

export type TrendingWindow = 'day' | 'week';

/** The sidebar badge: the stories of one window the reader has not opened (#2017). */
export interface TrendingUnopened {
  window: TrendingWindow;
  unopened: number;
}

/** A Trending article: its list row, and the posts that share it. */
export type StoryArticleDto = EntryDto & { shares: EntryDto[] };

/** A Trending story as its card shows it (#2447), on the Trending page and on the story's own page. */
export interface StoryCard {
  /** Stable across builds (#2128): a hide and a follow name the story by it. */
  id: number;
  /** The event the story's articles report, as a headline (#2329). */
  title: string;
  /** Two or three sentences on the event, null when the story's title is no LLM's (#2353). */
  synopsis: string | null;
  /** The reader's sources: the article feeds they follow and the accounts whose posts share a card (#2229). */
  outletCount: number;
  /** Posts sharing the story's articles, each post once. */
  shareCount: number;
  followed: boolean;
  createdAt: string;
  lastAddedAt: string;
}

/** A Trending story (#2017, #2328): its card and its articles, the lead first. */
export interface TrendingStoryDto extends StoryCard {
  /** The server's verdict: some entry of the story, a social one included, was opened. */
  viewed: boolean;
  leadEntryId: number;
  articles: StoryArticleDto[];
}

/** A story's counts as its card shows them. */
export type StorySources = Pick<StoryCard, 'outletCount' | 'shareCount'>;

/** What Follow and Hide need of a story. */
export type StoryRef = Pick<StoryCard, 'id' | 'followed'>;

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
  /** Null when the story has no title. */
  story: StoryCard | null;
  /** The rows the story's list holds whichever page it is, and the unread ones among them (#2329). */
  memberCount: number;
  unreadCount: number;
  /** As a Trending card counts them, over all the story's members (#2442). */
  outletCount: number;
  shareCount: number;
  lastOpenedAt: string | null;
  /** Kept for other clients such as iOS; the web client derives its marks from `lastOpenedAt`. */
  newEntryIds: number[];
}
