import { ImportRow, RowsByTab, tally } from './social-import-rows';

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
  });
  const rows: RowsByTab = {
    accounts: [row('a', 'new', 'ada'), row('b', 'subscribed', 'bea'), row('c', 'new', 'cara')],
    lists: [row('l', 'new', 'climate')],
    customFeeds: [],
  };

  it('counts every tab against the needle and only the open tab by state', () => {
    const result = tally(rows, {
      tabs: ['accounts', 'lists', 'customFeeds'],
      current: 'accounts',
      needle: 'a',
    });

    expect(result.tabCounts).toEqual({ accounts: 3, lists: 1, customFeeds: 0 });
    expect(result.stateCounts).toEqual({ all: 3, new: 2, subscribed: 1, no_longer_followed: 0 });
    expect(result.matched.map((match) => match.key)).toEqual(['a', 'b', 'c']);
  });

  it('leaves out the tabs it is not given', () => {
    const result = tally(rows, { tabs: ['accounts'], current: 'accounts', needle: 'car' });

    expect(result.tabCounts).toEqual({ accounts: 1, lists: 0, customFeeds: 0 });
    expect(result.matched.map((match) => match.key)).toEqual(['c']);
  });
});
