import { FeedDirectoryCountryFile } from './feed-directory.models';
import { feedDirectoryUrl } from './feed-directory-url';
import {
  FeedDirectoryCriteria,
  FeedDirectoryFilter,
  FeedDirectoryRow,
  countByCountry,
  feedsIn,
  filterRows,
  lengthOf,
  prepareRows,
  sameLookup,
  subscribedLookup,
  withSubscriptions,
} from './feed-directory-rows';

const DE: FeedDirectoryCountryFile = {
  country: 'DE',
  categories: ['Politics & Society', 'Sports'],
  languages: ['de', 'en'],
  domains: [
    [
      'zeit.de',
      998,
      1770,
      [0],
      [
        [0, 0, ['https://www.zeit.de/politik/index']],
        [1, 1, ['https://zeit.de/podcast']],
      ],
      'Weekly newspaper.',
    ],
    ['example.de', null, 300, [1], [[1, 0, ['https://example.de/feed']]], 'English only.'],
    [
      'heise.de',
      776,
      1334,
      [0, 1],
      [[0, 0, ['https://www.heise.de/rss/heise.rdf', 'https://www.heise.de/security/rss']]],
      'IT news.',
    ],
    ['b.de', null, null, [0], [[1, 0, ['https://b.de/feed']]], null],
  ],
};
const ANY_CRITERIA: FeedDirectoryCriteria = {
  mode: 'channels',
  categories: new Set(),
  lengths: new Set(),
  languages: new Set(),
  query: '',
};
const ALL: FeedDirectoryFilter = { ...ANY_CRITERIA, country: null, sort: 'top' };

describe('feed directory rows', () => {
  const rows = prepareRows(DE, new Set(['de']));

  it('drops domains with none of the scoped languages', () => {
    expect(rows.map((row) => row.host)).toEqual(['zeit.de', 'heise.de', 'b.de']);
  });

  it('splits channels from podcasts', () => {
    const zeit = rows[0];
    expect(feedsIn(zeit, 'channels').map((feed) => feed.url)).toEqual([
      'https://www.zeit.de/politik/index',
    ]);
    expect(feedsIn(zeit, 'podcasts').map((feed) => feed.url)).toEqual(['https://zeit.de/podcast']);
  });

  it('buckets lengths like InfoBubble', () => {
    expect([lengthOf(499), lengthOf(500), lengthOf(2499), lengthOf(2500), lengthOf(null)]).toEqual([
      'headlines',
      'summaries',
      'summaries',
      'long',
      null,
    ]);
  });

  it('sorts by rank with unranked last, then host', () => {
    expect(filterRows(rows, ALL).map((row) => row.host)).toEqual(['heise.de', 'zeit.de', 'b.de']);
  });

  it('sorts a to z', () => {
    expect(filterRows(rows, { ...ALL, sort: 'az' }).map((row) => row.host)).toEqual([
      'b.de',
      'heise.de',
      'zeit.de',
    ]);
  });

  it('sorts by feed count in the current mode, then rank', () => {
    expect(filterRows(rows, { ...ALL, sort: 'feeds' }).map((row) => row.host)).toEqual([
      'heise.de',
      'zeit.de',
      'b.de',
    ]);
  });

  it('hides a domain without feeds in the mode', () => {
    expect(filterRows(rows, { ...ALL, mode: 'podcasts' }).map((row) => row.host)).toEqual([
      'zeit.de',
    ]);
  });

  it('filters by category within the mode', () => {
    expect(
      filterRows(rows, { ...ALL, categories: new Set(['Sports']) }).map((row) => row.host),
    ).toEqual(['b.de']);
  });

  it('filters by length', () => {
    expect(filterRows(rows, { ...ALL, lengths: new Set(['long']) })).toEqual([]);
    expect(
      filterRows(rows, { ...ALL, lengths: new Set(['summaries']) }).map((row) => row.host),
    ).toEqual(['heise.de', 'zeit.de']);
  });

  it('filters by language', () => {
    expect(filterRows(rows, { ...ALL, languages: new Set(['en']) }).map((row) => row.host)).toEqual(
      ['heise.de'],
    );
  });

  it('searches host and summary, case-insensitively', () => {
    expect(filterRows(rows, { ...ALL, query: 'WEEKLY' }).map((row) => row.host)).toEqual([
      'zeit.de',
    ]);
    expect(filterRows(rows, { ...ALL, query: 'heise' }).map((row) => row.host)).toEqual([
      'heise.de',
    ]);
  });

  it('counts per country by the criteria', () => {
    expect(countByCountry(rows, ANY_CRITERIA)).toEqual(new Map([['DE', 3]]));
    expect(countByCountry(rows, { ...ANY_CRITERIA, query: 'WEEKLY' })).toEqual(
      new Map([['DE', 1]]),
    );
  });

  it('filters by country', () => {
    expect(filterRows(rows, { ...ALL, country: 'AT' })).toEqual([]);
  });

  it('counts the feeds and categories of each mode', () => {
    const zeit = rows[0];
    expect(zeit.byMode.channels).toEqual({
      feedCount: 1,
      categories: new Set(['Politics & Society']),
    });
    expect(zeit.byMode.podcasts).toEqual({ feedCount: 1, categories: new Set(['Sports']) });
  });

  describe('withSubscriptions', () => {
    const lookupOf = (feedUrls: string[]) =>
      subscribedLookup(
        new Map(feedUrls.map((feedUrl) => [feedDirectoryUrl(feedUrl), { feedUrl }])),
      );
    const unmarked = (): FeedDirectoryRow[] => prepareRows(DE, new Set(['de', 'en']));
    const heiseOf = (marked: FeedDirectoryRow[]): FeedDirectoryRow =>
      marked.find((row) => row.host === 'heise.de')!;

    it('marks a feed whose normalised address is subscribed', () => {
      const marked = withSubscriptions(unmarked(), lookupOf(['http://heise.de/rss/heise.rdf/']));
      expect(heiseOf(marked).feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
      expect(heiseOf(marked).following).toBe(true);
    });

    it('follows a site when a subscribed feed sits on it', () => {
      const marked = withSubscriptions(unmarked(), lookupOf(['https://www.heise.de/other.xml']));
      expect(heiseOf(marked).following).toBe(true);
      expect(heiseOf(marked).feeds.every((feed) => !feed.subscribed)).toBe(true);
    });

    it('follows a subdomain host too', () => {
      const marked = withSubscriptions(unmarked(), lookupOf(['https://feeds.zeit.de/x']));
      expect(marked.find((row) => row.host === 'zeit.de')!.following).toBe(true);
    });

    it('hands back every row it leaves unmarked as it was', () => {
      const rows = unmarked();
      const marked = withSubscriptions(rows, lookupOf(['http://heise.de/rss/heise.rdf/']));
      expect(marked.filter((row, index) => row !== rows[index]).map((row) => row.host)).toEqual([
        'heise.de',
      ]);
      expect(withSubscriptions(rows, lookupOf([])).every((row, index) => row === rows[index])).toBe(
        true,
      );
    });

    it('takes two lookups of the same addresses as the same', () => {
      const heise = 'https://www.heise.de/rss/heise.rdf';
      expect(sameLookup(lookupOf([heise]), lookupOf(['http://heise.de/rss/heise.rdf/']))).toBe(
        true,
      );
      expect(sameLookup(lookupOf([heise]), lookupOf([heise, 'https://b.de/feed']))).toBe(false);
      expect(sameLookup(lookupOf([heise]), lookupOf(['https://b.de/feed']))).toBe(false);
    });

    it('follows a row by its bare host, whatever www prefix the directory gives it', () => {
      const wwwDe: FeedDirectoryCountryFile = {
        ...DE,
        domains: [['www.zeit.de', 1, null, [0], [[0, 0, ['https://www.zeit.de/feed']]], null]],
      };
      const [row] = withSubscriptions(
        prepareRows(wwwDe, new Set(['de'])),
        lookupOf(['https://zeit.de/x']),
      );
      expect(row.following).toBe(true);
    });

    it('does not follow a row through a host that merely ends in its name', () => {
      const marked = withSubscriptions(unmarked(), lookupOf(['https://notzeit.de/x']));
      expect(marked.find((row) => row.host === 'zeit.de')!.following).toBe(false);
    });

    it('marks the feed but does not follow the row when the feed sits on another host', () => {
      const anchorFeed = 'https://anchor.fm/s/1/podcast/rss';
      const podcastDe: FeedDirectoryCountryFile = {
        ...DE,
        domains: [['podcast.de', 10, null, [0], [[1, 1, [anchorFeed]]], null]],
      };
      const [row] = withSubscriptions(
        prepareRows(podcastDe, new Set(['de'])),
        lookupOf([anchorFeed]),
      );
      expect(row.feeds[0].subscribed).toBe(true);
      expect(row.following).toBe(false);
    });
  });
});
