import { ImportRow, RowsByTab, buildRows, removals, tally } from './social-import-rows';

describe('tally', () => {
  const row = (key: string, state: ImportRow['state'], haystack: string): ImportRow => ({
    key,
    testId: key,
    state,
    name: key,
    handle: null,
    avatarUrl: null,
    icon: null,
    haystack,
    stateLabel: state,
    checkboxLabel: key,
    removable: false,
  });
  const rows: RowsByTab = {
    accounts: [row('a', 'new', 'ada'), row('b', 'subscribed', 'bea'), row('c', 'new', 'cara')],
    lists: [row('l', 'new', 'climate')],
    customFeeds: [],
    tags: [],
  };

  it('counts every tab against the needle and only the open tab by state', () => {
    const result = tally(rows, {
      tabs: ['accounts', 'lists', 'customFeeds'],
      current: 'accounts',
      needle: 'a',
    });

    expect(result.tabCounts).toEqual({ accounts: 3, lists: 1, customFeeds: 0, tags: 0 });
    expect(result.stateCounts).toEqual({ all: 3, new: 2, subscribed: 1, no_longer_followed: 0 });
    expect(result.matched.map((match) => match.key)).toEqual(['a', 'b', 'c']);
  });

  it('leaves out the tabs it is not given', () => {
    const result = tally(rows, { tabs: ['accounts'], current: 'accounts', needle: 'car' });

    expect(result.tabCounts).toEqual({ accounts: 1, lists: 0, customFeeds: 0, tags: 0 });
    expect(result.matched.map((match) => match.key)).toEqual(['c']);
  });
});

describe('removals', () => {
  it('lists the unticked subscribed follows and collections, skipping new rows', () => {
    const candidates = {
      follows: [
        {
          identity: 'a',
          handle: '@a',
          displayName: 'Ann',
          avatarUrl: null,
          feedUrl: 'u:a',
          state: 'subscribed' as const,
          subscriptionId: 1,
        },
        {
          identity: 'b',
          handle: 'did:plc:b',
          displayName: null,
          avatarUrl: null,
          feedUrl: 'u:b',
          state: 'no_longer_followed' as const,
          subscriptionId: 2,
        },
        {
          identity: 'c',
          handle: '@c',
          displayName: null,
          avatarUrl: null,
          feedUrl: 'u:c',
          state: 'new' as const,
          subscriptionId: null,
        },
      ],
      lists: [
        {
          kind: 'home' as const,
          id: null,
          name: 'Home',
          state: 'subscribed' as const,
          subscriptionId: 3,
        },
      ],
      customFeeds: [],
      tags: [
        {
          kind: 'tag' as const,
          id: 'caturday',
          name: '#Caturday',
          state: 'subscribed' as const,
          subscriptionId: 9,
        },
      ],
      truncated: false,
    };

    expect(
      removals(
        candidates,
        new Set(['follow:u:a', 'follow:u:b', 'follow:u:c', 'home:', 'tag:caturday']),
        (key) => (key === 'settings.social.importHome' ? 'Home timeline' : key),
      ),
    ).toEqual([
      { name: 'Ann', subscriptionId: 1 },
      { name: 'did:plc:b', subscriptionId: 2 },
      { name: 'Home timeline', subscriptionId: 3 },
      { name: '#Caturday', subscriptionId: 9 },
    ]);
  });
});

describe('buildRows', () => {
  it('puts followed tags in their own tab with the tag icon', () => {
    const rows = buildRows(
      {
        follows: [],
        lists: [],
        customFeeds: [],
        tags: [
          { kind: 'tag', id: 'caturday', name: '#Caturday', state: 'new', subscriptionId: null },
        ],
        truncated: false,
      },
      (key) => key,
    );

    expect(rows.tags.map((row) => [row.key, row.name, row.icon])).toEqual([
      ['tag:caturday', '#Caturday', 'tag'],
    ]);
  });
});
