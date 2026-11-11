import { FeedDirectoryCountryFile } from './feed-directory.models';
import {
  FeedDirectoryFilter,
  countByCountry,
  feedsIn,
  filterRows,
  lengthOf,
  prepareRows,
  withSubscribed,
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
const SUBSCRIBED = { urls: ['heise.de/rss/heise.rdf'], hosts: ['heise.de'] };
const ALL: FeedDirectoryFilter = {
  mode: 'channels',
  country: null,
  categories: new Set(),
  lengths: new Set(),
  languages: new Set(),
  query: '',
  sort: 'top',
};

describe('feed directory rows', () => {
  const rows = prepareRows(DE, new Set(['de']), SUBSCRIBED);

  it('drops domains with none of the scoped languages', () => {
    expect(rows.map((row) => row.host)).toEqual(['zeit.de', 'heise.de', 'b.de']);
  });

  it('marks a subscribed feed by its normalised url and the row as followed by host', () => {
    const heise = rows.find((row) => row.host === 'heise.de')!;
    expect(heise.following).toBe(true);
    expect(heise.feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
  });

  it('follows a subdomain host too', () => {
    const [row] = prepareRows(DE, new Set(['de']), { urls: [], hosts: ['feeds.zeit.de'] });
    expect(row.following).toBe(true);
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

  it('counts per country ignoring the country filter', () => {
    expect(countByCountry(rows, { ...ALL, country: 'AT' })).toEqual(new Map([['DE', 3]]));
  });

  it('filters by country', () => {
    expect(filterRows(rows, { ...ALL, country: 'AT' })).toEqual([]);
  });

  it('marks one more feed subscribed', () => {
    const next = withSubscribed(rows, 'https://www.zeit.de/politik/index');
    expect(next[0].feeds[0].subscribed).toBe(true);
    expect(next[0].following).toBe(true);
  });
});
