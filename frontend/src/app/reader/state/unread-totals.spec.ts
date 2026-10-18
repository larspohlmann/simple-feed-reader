import {
  NO_UNREAD_TOTALS,
  UnreadTotals,
  sameUnreadTotals,
  unreadTotalsFrom,
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
