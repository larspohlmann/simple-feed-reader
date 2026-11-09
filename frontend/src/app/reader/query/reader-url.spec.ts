import { DefaultUrlSerializer, PRIMARY_OUTLET, convertToParamMap } from '@angular/router';
import { EntryQuery } from '../models';
import { readerMatcher, selectionFromRoute } from './reader-matcher';
import { ViewContext } from './query';
import { queryFromSelection } from './entry-query';

const ON: ViewContext = { trending: true, trendingWindow: 'day' };
const OFF: ViewContext = { trending: false, trendingWindow: 'day' };

interface Opened {
  kind: string;
  query: EntryQuery;
  entryId: number | null;
  urlCorrection: Record<string, string | null> | null;
}

/** What the reader opens for a URL, in the terms the request and the address bar see. */
function opened(url: string, context: ViewContext): Opened | null {
  const tree = new DefaultUrlSerializer().parse(url);
  const match = readerMatcher(tree.root.children[PRIMARY_OUTLET]?.segments ?? []);
  if (match === null) return null;
  const savedSearch = match.posParams?.['savedSearch']?.path;
  const pathParams = convertToParamMap(savedSearch === undefined ? {} : { savedSearch });
  const parsed = selectionFromRoute(pathParams, convertToParamMap(tree.queryParams), context);
  return {
    kind: parsed.selection.kind,
    query: queryFromSelection(parsed.selection),
    entryId: parsed.entryId,
    urlCorrection: parsed.urlCorrection,
  };
}

const list = (kind: string, query: EntryQuery, entryId: number | null = null): Opened => ({
  kind,
  query,
  entryId,
  urlCorrection: null,
});

describe('the reader URLs (#2107 pin)', () => {
  it.each<[string, Opened]>([
    ['/', list('all', { view: 'all' })],
    ['/?unread=1', list('all', { view: 'all' })],
    ['/?entry=42', list('all', { view: 'all' }, 42)],
    ['/?entry=514-some-title', list('all', { view: 'all' }, 514)],
    ['/?subscription=7&entry=42', list('subscription', { view: 'all', subscription: 7 }, 42)],
    ['/?subscription=0', list('all', { view: 'all' })],
    ['/?subscription=abc', list('all', { view: 'all' })],
    ['/?tag=3', list('tag', { view: 'all', tag: 3 })],
    ['/?tag=x', list('all', { view: 'all' })],
    ['/?tag=-2', list('all', { view: 'all' })],
    ['/?subscription=7&tag=3', list('subscription', { view: 'all', subscription: 7 })],
    ['/?tag=3&network=mastodon', list('tag', { view: 'all', tag: 3 })],
    ['/?network=mastodon', list('network', { view: 'all', network: 'mastodon' })],
    ['/?network=bluesky', list('network', { view: 'all', network: 'bluesky' })],
    [
      '/?network=bluesky&feedKind=profile',
      list('network', { view: 'all', network: 'bluesky', feedKind: 'profile' }),
    ],
    [
      '/?network=mastodon&feedKind=search',
      list('network', { view: 'all', network: 'mastodon', feedKind: 'search' }),
    ],
    ['/?network=mastodon&feedKind=home', list('network', { view: 'all', network: 'mastodon' })],
    ['/?network=myspace', list('all', { view: 'all' })],
    ['/?network=mastodon&group=tags', list('network', { view: 'all', network: 'mastodon' })],
    ['/?group=tags', list('group', { view: 'all', group: 'tags' })],
    ['/?group=feeds', list('group', { view: 'all', group: 'feeds' })],
    ['/?group=folders', list('all', { view: 'all' })],
    ['/?view=favorites', list('favorites', { view: 'favorites' })],
    ['/?view=kept', list('kept', { view: 'kept' })],
    ['/?view=shared', list('shared', { view: 'shared' })],
    ['/?view=viewed', list('viewed', { view: 'viewed' })],
    ['/?view=for-you', list('for-you', { view: 'for-you' })],
    ['/?view=for-you&unread=1', list('for-you', { view: 'for-you' })],
    ['/?view=saved-searches', list('saved-searches', { view: 'saved-searches' })],
    ['/?view=favorites&tag=3', list('favorites', { view: 'favorites' })],
    ['/?view=unknown&tag=3', list('tag', { view: 'all', tag: 3 })],
    ['/?view=kept&window=week', list('kept', { view: 'kept' })],
    ['/?view=trending&window=week', list('trending', { view: 'trending', window: 'week' })],
    ['/?view=trending&window=day', list('trending', { view: 'trending', window: 'day' })],
    [
      '/?view=trending',
      {
        ...list('trending', { view: 'trending', window: 'day' }),
        urlCorrection: { window: 'day' },
      },
    ],
    [
      '/?view=trending&window=month',
      {
        ...list('trending', { view: 'trending', window: 'day' }),
        urlCorrection: { window: 'day' },
      },
    ],
    ['/?q=angular', list('search', { view: 'all', q: 'angular' })],
    ['/?q=punk%20', list('search', { view: 'all', q: 'punk ' })],
    ['/?q=%20%20angular%20%20%20js%20%20', list('search', { view: 'all', q: 'angular js ' })],
    ['/?q=angular&tag=3', list('search', { view: 'all', q: 'angular' })],
    ['/?view=saved-searches&q=climate', list('search', { view: 'all', q: 'climate' })],
    ['/?view=trending&q=climate', list('search', { view: 'all', q: 'climate' })],
    ['/?q=an', list('all', { view: 'all' })],
    ['/?q=ab%20', list('all', { view: 'all' })],
    ['/?q=', list('all', { view: 'all' })],
    ['/?q=%20%20%20', list('all', { view: 'all' })],
    ['/searches/saved/all', list('saved-searches', { view: 'saved-searches' })],
    ['/searches/saved/42', list('saved-search', { view: 'all', savedSearchId: 42 })],
    ['/searches/saved/42-climate', list('saved-search', { view: 'all', savedSearchId: 42 })],
    ['/searches/saved/climate', list('saved-searches', { view: 'saved-searches' })],
    ['/searches/saved/42x', list('saved-searches', { view: 'saved-searches' })],
    [
      '/searches/saved/42-climate?entry=99',
      list('saved-search', { view: 'all', savedSearchId: 42 }, 99),
    ],
    ['/searches/saved/all?entry=99', list('saved-searches', { view: 'saved-searches' }, 99)],
    [
      '/searches/saved/42-climate?q=angular&tag=3',
      list('saved-search', { view: 'all', savedSearchId: 42 }),
    ],
    [
      '/searches/saved/42-climate?unread=1',
      list('saved-search', { view: 'all', savedSearchId: 42 }),
    ],
  ])('opens %s', (url, expected) => {
    expect(opened(url, ON)).toEqual(expected);
  });

  it.each<[string, Opened]>([
    [
      '/?view=trending&window=week',
      { ...list('all', { view: 'all' }), urlCorrection: { view: null, window: null } },
    ],
    [
      '/?view=trending&tag=3',
      { ...list('tag', { view: 'all', tag: 3 }), urlCorrection: { view: null, window: null } },
    ],
  ])('opens %s on an account that switched Trending off', (url, expected) => {
    expect(opened(url, OFF)).toEqual(expected);
  });

  it.each(['/settings', '/searches/saved', '/searches/saved/1/extra', '/searches/42'])(
    'leaves %s to another route',
    (url) => {
      expect(opened(url, ON)).toBeNull();
    },
  );
});
