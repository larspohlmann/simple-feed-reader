import { SubscriptionDto } from '../models';
import { SocialFeedRefDto } from '../social-feed';
import { makeSubscription } from '../testing/subscription.factory';
import { buildSocialSections, isPrivateFeed, isSocialFeed, networkCounts } from './social-sections';

const ref = (
  network: 'bluesky' | 'mastodon',
  accountId: number,
  kind: 'home' | 'list' | 'custom_feed',
): SocialFeedRefDto => ({ network, accountId, kind });

const profile = (id: number, network: 'bluesky' | 'mastodon', title: string): SubscriptionDto =>
  feed(id, { network, accountId: null, kind: 'profile' }, title);

const feed = (id: number, social: SocialFeedRefDto, title = `Feed ${id}`): SubscriptionDto =>
  makeSubscription({ id, title, social });

describe('buildSocialSections', () => {
  it('builds one section per network holding a private feed, Bluesky first', () => {
    const sections = buildSocialSections(
      [
        feed(1, ref('mastodon', 8, 'home')),
        feed(2, ref('bluesky', 3, 'home')),
        makeSubscription({ id: 3 }),
      ],
      [],
    );

    expect(sections.map((section) => section.network)).toEqual(['bluesky', 'mastodon']);
  });

  it('leaves out a network without private feeds', () => {
    expect(buildSocialSections([makeSubscription()], [])).toEqual([]);
  });

  it("orders accounts by id and each account's feeds Home, lists, custom feeds, then by title", () => {
    const sections = buildSocialSections(
      [
        feed(1, ref('bluesky', 9, 'home')),
        feed(2, ref('bluesky', 3, 'custom_feed'), 'Art'),
        feed(3, ref('bluesky', 3, 'list'), 'Writers'),
        feed(4, ref('bluesky', 3, 'list'), 'Artists'),
        feed(5, ref('bluesky', 3, 'home')),
      ],
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social' }],
    );

    expect(
      sections[0].accounts.map((group) => [
        group.accountId,
        group.handle,
        group.subscriptions.map((subscription) => subscription.id),
      ]),
    ).toEqual([
      [3, '@alice.bsky.social', [5, 4, 3, 2]],
      [9, null, [1]],
    ]);
  });

  it('shows a network that has only profile feeds, with no account group', () => {
    const sections = buildSocialSections([profile(1, 'mastodon', 'Bob')], []);

    expect(sections.map((section) => [section.network, section.accounts.length])).toEqual([
      ['mastodon', 0],
    ]);
    expect(sections[0].people.map((subscription) => subscription.id)).toEqual([1]);
  });

  it('keeps private feeds per account and lists profile feeds alphabetically as people', () => {
    const sections = buildSocialSections(
      [
        profile(1, 'bluesky', 'Zed'),
        feed(2, ref('bluesky', 3, 'home')),
        profile(3, 'bluesky', 'amy'),
        profile(4, 'mastodon', 'Other network'),
      ],
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social' }],
    );

    expect(sections[0].accounts.map((group) => group.accountId)).toEqual([3]);
    expect(sections[0].people.map((subscription) => subscription.title)).toEqual(['amy', 'Zed']);
  });

  it('counts a profile feed as social but not as private', () => {
    const person = profile(1, 'bluesky', 'Amy');

    expect(isSocialFeed(person)).toBe(true);
    expect(isPrivateFeed(person)).toBe(false);
    expect(isSocialFeed(makeSubscription())).toBe(false);
  });

  it('tells a private feed from an ordinary one', () => {
    expect(isPrivateFeed(feed(1, ref('bluesky', 3, 'home')))).toBe(true);
    expect(isPrivateFeed(makeSubscription())).toBe(false);
  });

  it("sums a network's unread over its private and profile feeds, excluded ones included", () => {
    const subscriptions = [
      {
        ...feed(1, ref('bluesky', 3, 'home')),
        unreadCount: 2,
        entryCount: 20,
        includeInAllItems: false,
      },
      { ...profile(2, 'bluesky', 'Amy'), unreadCount: 5, entryCount: 50 },
      { ...profile(3, 'mastodon', 'Bob'), unreadCount: 7, entryCount: 70 },
      { ...makeSubscription({ id: 4 }), unreadCount: 11, entryCount: 110 },
    ];

    expect(networkCounts(subscriptions, 'bluesky')).toEqual({ unreadCount: 7, entryCount: 70 });
    expect(buildSocialSections(subscriptions, [])[0].unreadCount).toBe(7);
  });
});
