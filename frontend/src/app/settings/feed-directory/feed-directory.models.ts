export interface FeedDirectoryIndexCountry {
  code: string;
  name: string;
  sites: number;
  languages: Readonly<Record<string, number>>;
}

export interface FeedDirectoryIndex {
  countries: readonly FeedDirectoryIndexCountry[];
  languages: Readonly<Record<string, number>>;
}

/** [categoryIndex, isPodcast, feedUrls] */
export type FeedDirectoryGroupTuple = readonly [number, 0 | 1, readonly string[]];

/** [host, rank, avgLength, languageIndexes, groups, summary] */
export type FeedDirectoryDomainTuple = readonly [
  string,
  number | null,
  number | null,
  readonly number[],
  readonly FeedDirectoryGroupTuple[],
  string | null,
];

export interface FeedDirectoryCountryFile {
  country: string;
  categories: readonly string[];
  languages: readonly string[];
  domains: readonly FeedDirectoryDomainTuple[];
}

export interface FeedDirectorySubscribed {
  urls: readonly string[];
  hosts: readonly string[];
}
