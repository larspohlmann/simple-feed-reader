import { FeedDirectoryCountryFile } from '../app/settings/feed-directory/feed-directory.models';

/** A German country file of `count` one-feed sites, `site0.de` to `site<count-1>.de`, ranked in that order. */
export function manySitesFile(count: number): FeedDirectoryCountryFile {
  return {
    country: 'DE',
    categories: ['News'],
    languages: ['de'],
    domains: Array.from({ length: count }, (_unused, index) => [
      `site${index}.de`,
      index + 1,
      1000,
      [0],
      [[0, 0, [`https://site${index}.de/feed`]]],
      null,
    ]),
  };
}
