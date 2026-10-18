import {
  NO_UNREAD_TOTALS,
  UnreadTotals,
  sameUnreadTotals,
  unreadTotalsFrom,
  zeroUnreadTotals,
} from './unread-totals';

const totals: UnreadTotals = {
  allItems: 10,
  tags: new Map([
    [3, 4],
    [5, 2],
  ]),
  networks: new Map([
    ['bluesky', 6],
    ['mastodon', 1],
  ]),
  networkProfiles: new Map([
    ['bluesky', 3],
    ['mastodon', 0],
  ]),
};

describe('unreadTotalsFrom', () => {
  it('maps the DTO lists into maps', () => {
    const result = unreadTotalsFrom({
      allItems: 7,
      tags: [{ id: 3, unreadCount: 4 }],
      networks: [
        { network: 'bluesky', unreadCount: 6, profilesUnreadCount: 2 },
        { network: 'mastodon', unreadCount: 1, profilesUnreadCount: 0 },
      ],
    });

    expect(result.allItems).toBe(7);
    expect([...result.tags]).toEqual([[3, 4]]);
    expect([...result.networks]).toEqual([
      ['bluesky', 6],
      ['mastodon', 1],
    ]);
    expect([...result.networkProfiles]).toEqual([
      ['bluesky', 2],
      ['mastodon', 0],
    ]);
  });
});

describe('zeroUnreadTotals', () => {
  it("zeroes the all-items total for 'all'", () => {
    expect(zeroUnreadTotals(totals, 'all').allItems).toBe(0);
  });

  it('zeroes one tag and keeps the others', () => {
    const result = zeroUnreadTotals(totals, { tag: 3 });

    expect([...result.tags]).toEqual([
      [3, 0],
      [5, 2],
    ]);
  });

  it('zeroes a network, profiles included', () => {
    const result = zeroUnreadTotals(totals, { network: 'bluesky' });

    expect(result.networks.get('bluesky')).toBe(0);
    expect(result.networks.get('mastodon')).toBe(1);
    expect(result.networkProfiles.get('bluesky')).toBe(0);
  });

  it('zeroes only the profile total for the profile feed kind', () => {
    const result = zeroUnreadTotals(totals, { network: 'bluesky', feedKind: 'profile' });

    expect(result.networkProfiles.get('bluesky')).toBe(0);
    expect(result.networks.get('bluesky')).toBe(6);
  });

  it('leaves the totals as they are for a single subscription', () => {
    expect(zeroUnreadTotals(totals, { subscription: 1 })).toBe(totals);
  });
});

describe('sameUnreadTotals', () => {
  it('is true for structurally equal totals', () => {
    expect(sameUnreadTotals(totals, { ...totals, tags: new Map(totals.tags) })).toBe(true);
    expect(
      sameUnreadTotals(NO_UNREAD_TOTALS, unreadTotalsFrom({ allItems: 0, tags: [], networks: [] })),
    ).toBe(true);
  });

  it('is false when one map value differs', () => {
    expect(
      sameUnreadTotals(totals, {
        ...totals,
        tags: new Map([
          [3, 4],
          [5, 3],
        ]),
      }),
    ).toBe(false);
    expect(sameUnreadTotals(totals, { ...totals, allItems: 9 })).toBe(false);
  });
});
