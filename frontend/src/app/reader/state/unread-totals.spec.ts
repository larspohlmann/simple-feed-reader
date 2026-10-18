import { SubscriptionDto, SubscriptionTagDto } from '../models';
import { makeSubscription } from '../testing/subscription.factory';
import {
  NO_UNREAD_TOTALS,
  UnreadTotals,
  sameUnreadTotals,
  shiftUnreadTotals,
  unreadTotalsFrom,
  zeroUnreadTotals,
} from './unread-totals';

const tag = (id: number): SubscriptionTagDto => ({
  id,
  name: `Tag ${id}`,
  color: null,
  icon: null,
  position: id,
});

const blueskyHome = (id: number, overrides: Partial<SubscriptionDto> = {}): SubscriptionDto =>
  makeSubscription({
    id,
    social: { network: 'bluesky', accountId: 1, kind: 'home' },
    ...overrides,
  });

const blueskyProfile = (id: number): SubscriptionDto =>
  makeSubscription({ id, social: { network: 'bluesky', accountId: null, kind: 'profile' } });

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

describe('shiftUnreadTotals', () => {
  it('moves each scope once per copy of the article it holds', () => {
    const copies = [blueskyHome(1, { tags: [tag(3)] }), blueskyHome(2, { tags: [tag(3), tag(5)] })];

    const result = shiftUnreadTotals(totals, copies, -1);

    expect(result.allItems).toBe(8);
    expect(result.tags.get(3)).toBe(2);
    expect(result.tags.get(5)).toBe(1);
    expect(result.networks.get('bluesky')).toBe(4);
  });

  it('leaves the all-items total alone for copies outside All items', () => {
    const result = shiftUnreadTotals(totals, [makeSubscription({ includeInAllItems: false })], -1);

    expect(result.allItems).toBe(10);
  });

  it('moves the profile total for a profile copy but not for a Home copy', () => {
    expect(shiftUnreadTotals(totals, [blueskyProfile(1)], -1).networkProfiles.get('bluesky')).toBe(
      2,
    );
    expect(shiftUnreadTotals(totals, [blueskyHome(1)], -1).networkProfiles.get('bluesky')).toBe(3);
  });

  it('never goes below zero', () => {
    const result = shiftUnreadTotals(
      { ...totals, allItems: 0, tags: new Map([[3, 0]]) },
      [makeSubscription({ tags: [tag(3)] })],
      -1,
    );

    expect(result.allItems).toBe(0);
    expect(result.tags.get(3)).toBe(0);
  });

  it('counts a scope that had no entry yet', () => {
    const result = shiftUnreadTotals(totals, [makeSubscription({ tags: [tag(9)] })], 1);

    expect(result.tags.get(9)).toBe(1);
  });

  it('keeps the identity of every map no copy touches', () => {
    const result = shiftUnreadTotals(totals, [makeSubscription()], -1);

    expect(result.tags).toBe(totals.tags);
    expect(result.networks).toBe(totals.networks);
    expect(result.networkProfiles).toBe(totals.networkProfiles);
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
    expect(sameUnreadTotals(totals, shiftUnreadTotals(totals, [], 1))).toBe(true);
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
