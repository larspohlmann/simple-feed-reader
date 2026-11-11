import {
  FeedDirectoryCountryFile,
  FeedDirectoryDomainTuple,
  FeedDirectorySubscribed,
} from './feed-directory.models';
import { feedDirectoryHost, feedDirectoryUrl } from './feed-directory-url';

export type FeedDirectoryMode = 'channels' | 'podcasts';
export type FeedDirectoryLength = 'headlines' | 'summaries' | 'long';
export type FeedDirectorySort = 'top' | 'az' | 'feeds';

export interface FeedDirectoryFeed {
  readonly url: string;
  readonly category: string;
  readonly podcast: boolean;
  readonly subscribed: boolean;
}

export interface FeedDirectoryRow {
  readonly host: string;
  readonly country: string;
  readonly rank: number | null;
  readonly length: FeedDirectoryLength | null;
  readonly languages: readonly string[];
  readonly summary: string | null;
  readonly feeds: readonly FeedDirectoryFeed[];
  readonly following: boolean;
  readonly searchText: string;
}

export interface FeedDirectoryFilter {
  readonly mode: FeedDirectoryMode;
  readonly country: string | null;
  readonly categories: ReadonlySet<string>;
  readonly lengths: ReadonlySet<FeedDirectoryLength>;
  readonly languages: ReadonlySet<string>;
  readonly query: string;
  readonly sort: FeedDirectorySort;
}

interface SubscribedLookup {
  readonly urls: ReadonlySet<string>;
  readonly hosts: readonly string[];
}

const LONG_READ_CHARACTERS = 2500;
const SUMMARY_CHARACTERS = 500;

export function lengthOf(averageLength: number | null): FeedDirectoryLength | null {
  if (averageLength === null) return null;
  if (averageLength >= LONG_READ_CHARACTERS) return 'long';
  return averageLength >= SUMMARY_CHARACTERS ? 'summaries' : 'headlines';
}

export function prepareRows(
  file: FeedDirectoryCountryFile,
  scopeLanguages: ReadonlySet<string>,
  subscribed: FeedDirectorySubscribed,
): FeedDirectoryRow[] {
  const lookup: SubscribedLookup = { urls: new Set(subscribed.urls), hosts: subscribed.hosts };
  return file.domains
    .map((domain) => toRow(file, domain, lookup))
    .filter((row) => row.languages.some((language) => scopeLanguages.has(language)));
}

function toRow(
  file: FeedDirectoryCountryFile,
  [host, rank, averageLength, languageIndexes, groups, summary]: FeedDirectoryDomainTuple,
  subscribed: SubscribedLookup,
): FeedDirectoryRow {
  const feeds = groups.flatMap(([categoryIndex, podcast, urls]) =>
    urls.map((url) => ({
      url,
      category: file.categories[categoryIndex],
      podcast: podcast === 1,
      subscribed: subscribed.urls.has(feedDirectoryUrl(url)),
    })),
  );
  return {
    host,
    country: file.country,
    rank,
    length: lengthOf(averageLength),
    languages: languageIndexes.map((index) => file.languages[index]),
    summary,
    feeds,
    following: isFollowed(host, subscribed.hosts),
    searchText: `${host}\n${summary ?? ''}`.toLowerCase(),
  };
}

function isFollowed(host: string, followedHosts: readonly string[]): boolean {
  const domain = feedDirectoryHost(`https://${host}`);
  return followedHosts.some((followed) => followed === domain || followed.endsWith(`.${domain}`));
}

export function feedsIn(row: FeedDirectoryRow, mode: FeedDirectoryMode): FeedDirectoryFeed[] {
  return row.feeds.filter((feed) => feed.podcast === (mode === 'podcasts'));
}

function matches(row: FeedDirectoryRow, filter: FeedDirectoryFilter): boolean {
  const feeds = feedsIn(row, filter.mode);
  if (feeds.length === 0) return false;
  if (filter.categories.size && !feeds.some((feed) => filter.categories.has(feed.category)))
    return false;
  if (filter.lengths.size && (row.length === null || !filter.lengths.has(row.length))) return false;
  if (filter.languages.size && !row.languages.some((language) => filter.languages.has(language)))
    return false;
  return !filter.query || row.searchText.includes(filter.query.toLowerCase());
}

const byTop = (left: FeedDirectoryRow, right: FeedDirectoryRow): number =>
  (left.rank ?? Infinity) - (right.rank ?? Infinity) || left.host.localeCompare(right.host);

function comparator(
  filter: FeedDirectoryFilter,
): (left: FeedDirectoryRow, right: FeedDirectoryRow) => number {
  switch (filter.sort) {
    case 'az':
      return (left, right) => left.host.localeCompare(right.host);
    case 'feeds':
      return (left, right) =>
        feedsIn(right, filter.mode).length - feedsIn(left, filter.mode).length ||
        byTop(left, right);
    default:
      return byTop;
  }
}

export function filterRows(
  rows: readonly FeedDirectoryRow[],
  filter: FeedDirectoryFilter,
): FeedDirectoryRow[] {
  return rows
    .filter(
      (row) => (filter.country === null || row.country === filter.country) && matches(row, filter),
    )
    .sort(comparator(filter));
}

export function countByCountry(
  rows: readonly FeedDirectoryRow[],
  filter: FeedDirectoryFilter,
): Map<string, number> {
  const counts = new Map<string, number>();
  for (const row of rows) {
    if (matches(row, filter)) counts.set(row.country, (counts.get(row.country) ?? 0) + 1);
  }
  return counts;
}

export function withSubscribed(
  rows: readonly FeedDirectoryRow[],
  feedUrl: string,
): FeedDirectoryRow[] {
  return rows.map((row) =>
    row.feeds.some((feed) => feed.url === feedUrl)
      ? {
          ...row,
          following: row.following || isFollowed(row.host, [feedDirectoryHost(feedUrl)]),
          feeds: row.feeds.map((feed) =>
            feed.url === feedUrl ? { ...feed, subscribed: true } : feed,
          ),
        }
      : row,
  );
}
