import { FeedDirectoryCountryFile, FeedDirectoryDomainTuple } from './feed-directory.models';
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

export interface FeedDirectoryModeSummary {
  readonly feedCount: number;
  readonly categories: ReadonlySet<string>;
}

export interface FeedDirectoryRow {
  readonly host: string;
  readonly country: string;
  readonly rank: number | null;
  readonly length: FeedDirectoryLength | null;
  readonly languages: readonly string[];
  readonly summary: string | null;
  readonly feeds: readonly FeedDirectoryFeed[];
  readonly byMode: Readonly<Record<FeedDirectoryMode, FeedDirectoryModeSummary>>;
  readonly following: boolean;
  readonly searchText: string;
}

export interface FeedDirectoryCriteria {
  readonly mode: FeedDirectoryMode;
  readonly categories: ReadonlySet<string>;
  readonly lengths: ReadonlySet<FeedDirectoryLength>;
  readonly languages: ReadonlySet<string>;
  readonly query: string;
}

export interface FeedDirectoryFilter extends FeedDirectoryCriteria {
  readonly country: string | null;
  readonly sort: FeedDirectorySort;
}

export interface SubscribedLookup {
  readonly urls: ReadonlySet<string>;
  readonly domains: ReadonlySet<string>;
}

const LONG_READ_CHARACTERS = 2500;
const SUMMARY_CHARACTERS = 500;

export function lengthOf(averageLength: number | null): FeedDirectoryLength | null {
  if (averageLength === null) return null;
  if (averageLength >= LONG_READ_CHARACTERS) return 'long';
  return averageLength >= SUMMARY_CHARACTERS ? 'summaries' : 'headlines';
}

export function subscribedLookup(
  subscriptionsByUrl: ReadonlyMap<string, { feedUrl: string }>,
): SubscribedLookup {
  return {
    urls: new Set(subscriptionsByUrl.keys()),
    domains: enclosingDomains(
      [...subscriptionsByUrl.values()].map((subscription) =>
        feedDirectoryHost(subscription.feedUrl),
      ),
    ),
  };
}

export function prepareRows(
  file: FeedDirectoryCountryFile,
  scopeLanguages: ReadonlySet<string>,
): FeedDirectoryRow[] {
  return file.domains
    .filter(([, , , languageIndexes]) =>
      languageIndexes.some((index) => scopeLanguages.has(file.languages[index])),
    )
    .map((domain) => toRow(file, domain));
}

export function withSubscriptions(
  rows: readonly FeedDirectoryRow[],
  lookup: SubscribedLookup,
): FeedDirectoryRow[] {
  return rows.map((row) => {
    const following = lookup.domains.has(bareHost(row.host));
    const feeds = row.feeds.map((feed) => {
      const subscribed = lookup.urls.has(feedDirectoryUrl(feed.url));
      return subscribed === feed.subscribed ? feed : { ...feed, subscribed };
    });
    const unchanged =
      following === row.following && feeds.every((feed, index) => feed === row.feeds[index]);
    return unchanged ? row : { ...row, following, feeds };
  });
}

export function sameLookup(left: SubscribedLookup, right: SubscribedLookup): boolean {
  return left.urls.size === right.urls.size && [...left.urls].every((url) => right.urls.has(url));
}

export function modeSummaries(
  feeds: readonly FeedDirectoryFeed[],
): Record<FeedDirectoryMode, FeedDirectoryModeSummary> {
  const summaryOf = (podcast: boolean): FeedDirectoryModeSummary => {
    const members = feeds.filter((feed) => feed.podcast === podcast);
    return { feedCount: members.length, categories: new Set(members.map((feed) => feed.category)) };
  };
  return { channels: summaryOf(false), podcasts: summaryOf(true) };
}

function toRow(
  file: FeedDirectoryCountryFile,
  [host, rank, averageLength, languageIndexes, groups, summary]: FeedDirectoryDomainTuple,
): FeedDirectoryRow {
  const feeds = groups.flatMap(([categoryIndex, podcast, urls]) =>
    urls.map((url) => ({
      url,
      category: file.categories[categoryIndex],
      podcast: podcast === 1,
      subscribed: false,
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
    byMode: modeSummaries(feeds),
    following: false,
    searchText: `${host}\n${summary ?? ''}`.toLowerCase(),
  };
}

function enclosingDomains(hosts: readonly string[]): Set<string> {
  const domains = new Set<string>();
  for (const host of hosts) {
    const labels = host.split('.');
    labels.forEach((_label, index) => domains.add(labels.slice(index).join('.')));
  }
  return domains;
}

function bareHost(host: string): string {
  return host.startsWith('www.') ? host.slice('www.'.length) : host;
}

export function feedsIn(row: FeedDirectoryRow, mode: FeedDirectoryMode): FeedDirectoryFeed[] {
  return row.feeds.filter((feed) => feed.podcast === (mode === 'podcasts'));
}

export function shownFeeds(
  row: FeedDirectoryRow,
  mode: FeedDirectoryMode,
  categories: ReadonlySet<string>,
): FeedDirectoryFeed[] {
  const feeds = feedsIn(row, mode);
  return categories.size ? feeds.filter((feed) => categories.has(feed.category)) : feeds;
}

function matcher(criteria: FeedDirectoryCriteria): (row: FeedDirectoryRow) => boolean {
  const query = criteria.query.toLowerCase();
  const categories = [...criteria.categories];
  return (row) => {
    const summary = row.byMode[criteria.mode];
    if (summary.feedCount === 0) return false;
    if (categories.length && !categories.some((category) => summary.categories.has(category)))
      return false;
    if (criteria.lengths.size && (row.length === null || !criteria.lengths.has(row.length)))
      return false;
    if (
      criteria.languages.size &&
      !row.languages.some((language) => criteria.languages.has(language))
    )
      return false;
    return !query || row.searchText.includes(query);
  };
}

const byTop = (left: FeedDirectoryRow, right: FeedDirectoryRow): number =>
  (left.rank ?? Infinity) - (right.rank ?? Infinity) || left.host.localeCompare(right.host);

function comparator(
  filter: FeedDirectoryFilter,
): (left: FeedDirectoryRow, right: FeedDirectoryRow) => number {
  switch (filter.sort) {
    case 'az':
      return (left, right) => left.host.localeCompare(right.host);
    case 'feeds': {
      const shownCount = shownCounter(filter);
      return (left, right) => shownCount(right) - shownCount(left) || byTop(left, right);
    }
    default:
      return byTop;
  }
}

function shownCounter(filter: FeedDirectoryFilter): (row: FeedDirectoryRow) => number {
  if (!filter.categories.size) return (row) => row.byMode[filter.mode].feedCount;
  const counts = new Map<FeedDirectoryRow, number>();
  return (row) => {
    const known = counts.get(row);
    if (known !== undefined) return known;
    const count = shownFeeds(row, filter.mode, filter.categories).length;
    counts.set(row, count);
    return count;
  };
}

export function filterRows(
  rows: readonly FeedDirectoryRow[],
  filter: FeedDirectoryFilter,
): FeedDirectoryRow[] {
  const matches = matcher(filter);
  return rows
    .filter((row) => (filter.country === null || row.country === filter.country) && matches(row))
    .sort(comparator(filter));
}

export function countByCountry(
  rows: readonly FeedDirectoryRow[],
  criteria: FeedDirectoryCriteria,
): Map<string, number> {
  const matches = matcher(criteria);
  const counts = new Map<string, number>();
  for (const row of rows) {
    if (matches(row)) counts.set(row.country, (counts.get(row.country) ?? 0) + 1);
  }
  return counts;
}
