import { SubscriptionDto } from '../models';
import { SocialFeedRefDto } from '../social-feed';
import { makeSubscription } from '../testing/subscription.factory';
import { buildSocialSections, isPrivateFeed, isSocialFeed } from './social-sections';

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

  it('names every account of the network for its menu, by id, even one without private feeds', () => {
    const sections = buildSocialSections(
      [feed(1, ref('bluesky', 3, 'home'))],
      [
        { id: 9, network: 'bluesky', handle: '@bob.bsky.social', status: 'needs_reconnect' },
        { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
        { id: 5, network: 'mastodon', handle: '@carol@mastodon.example', status: 'connected' },
      ],
    );

    expect(sections[0].networkAccounts.map((account) => account.id)).toEqual([3, 9]);
  });

  it('leaves out a network without accounts or feeds', () => {
    expect(buildSocialSections([makeSubscription()], [])).toEqual([]);
  });

  it('keeps a network with an account but no feeds, so its head can still manage feeds', () => {
    const sections = buildSocialSections(
      [],
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' }],
    );

    expect(sections.map((section) => section.network)).toEqual(['bluesky']);
    expect(sections[0].accounts).toEqual([]);
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
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' }],
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
    expect(sections[0].showsHandles).toBe(true);
  });

  it('shows a network that has only profile feeds, with no account group', () => {
    const sections = buildSocialSections([profile(1, 'mastodon', 'Bob')], []);

    expect(sections.map((section) => [section.network, section.accounts.length])).toEqual([
      ['mastodon', 0],
    ]);
    expect(sections[0].profiles.subscriptions.map((subscription) => subscription.id)).toEqual([1]);
  });

  it('keeps private feeds per account and lists profile feeds alphabetically', () => {
    const sections = buildSocialSections(
      [
        profile(1, 'bluesky', 'Zed'),
        feed(2, ref('bluesky', 3, 'home')),
        profile(3, 'bluesky', 'amy'),
        profile(4, 'mastodon', 'Other network'),
      ],
      [{ id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' }],
    );

    expect(sections[0].accounts.map((group) => group.accountId)).toEqual([3]);
    expect(sections[0].showsHandles).toBe(false);
    expect(sections[0].profiles.subscriptions.map((subscription) => subscription.title)).toEqual([
      'amy',
      'Zed',
    ]);
  });

  it("sums only the profile feeds' counts into the profiles group", () => {
    const sections = buildSocialSections(
      [
        makeSubscription({
          id: 1,
          unreadCount: 5,
          entryCount: 9,
          social: { network: 'bluesky', accountId: 3, kind: 'home' },
        }),
        makeSubscription({
          id: 2,
          unreadCount: 2,
          entryCount: 4,
          social: { network: 'bluesky', accountId: null, kind: 'profile' },
        }),
        makeSubscription({
          id: 3,
          unreadCount: 1,
          entryCount: 1,
          social: { network: 'bluesky', accountId: null, kind: 'profile' },
        }),
      ],
      [{ id: 3, network: 'bluesky', handle: '@a', status: 'connected' }],
    );

    expect(sections[0].profiles.unreadCount).toBe(3);
    expect(sections[0].profiles.entryCount).toBe(5);
    expect(sections[0].unreadCount).toBe(8);
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

  it("sums a network's unread and entries over its private and profile feeds, excluded ones included", () => {
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

    const [bluesky, mastodon] = buildSocialSections(subscriptions, []);
    expect([bluesky.unreadCount, bluesky.entryCount]).toEqual([7, 70]);
    expect([mastodon.unreadCount, mastodon.entryCount]).toEqual([7, 70]);
  });
});

const saved = (id: number, network: 'bluesky' | 'mastodon', entryCount: number): SubscriptionDto =>
  makeSubscription({
    id,
    title: `Saved from ${network}`,
    sourceFormat: 'saved',
    entryCount,
    social: { network, accountId: null, kind: 'saved' },
  });

describe('saved feeds in the social sections', () => {
  it('shows a network’s saved feed apart from its profiles while it holds posts', () => {
    const [section] = buildSocialSections(
      [profile(1, 'bluesky', 'Bob'), saved(2, 'bluesky', 3)],
      [],
    );

    expect(section.saved?.id).toBe(2);
    expect(section.profiles.subscriptions.map(({ id }) => id)).toEqual([1]);
    expect(isPrivateFeed(saved(2, 'bluesky', 3))).toBe(false);
  });

  it('hides an empty saved feed and keeps a section that holds only saved posts', () => {
    expect(buildSocialSections([saved(2, 'bluesky', 0)], [])).toEqual([]);
    expect(buildSocialSections([saved(3, 'mastodon', 1)], [])[0].network).toBe('mastodon');
  });
});
