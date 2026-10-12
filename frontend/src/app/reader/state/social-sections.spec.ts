import { SubscriptionDto } from '../models';
import { SocialFeedRefDto } from '../social-feed';
import { makeSubscription } from '../testing/subscription.factory';
import { buildSocialSections, isPrivateFeed } from './social-sections';

const ref = (
  network: 'bluesky' | 'mastodon',
  accountId: number,
  kind: 'home' | 'list' | 'custom_feed',
): SocialFeedRefDto => ({ network, accountId, kind });

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

  it('tells a private feed from an ordinary one', () => {
    expect(isPrivateFeed(feed(1, ref('bluesky', 3, 'home')))).toBe(true);
    expect(isPrivateFeed(makeSubscription())).toBe(false);
  });
});
