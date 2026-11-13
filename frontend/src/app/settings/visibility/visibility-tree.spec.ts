import { SubscriptionDto } from '../../reader/models';
import { buildSocialSections } from '../../reader/state/social-sections';
import { buildTagTree } from '../../reader/state/subscription-derivations';
import { NO_UNREAD_TOTALS } from '../../reader/state/unread-totals';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { makeTag } from '../../reader/testing/tag.factory';
import { visibilityLines } from './visibility-tree';

const NEWS = makeTag(1, 'News');
const TECH = makeTag(2, 'Tech');
const SKEET = makeSubscription({
  id: 10,
  title: 'Alice on Bluesky',
  tags: [NEWS],
  social: { network: 'bluesky', kind: 'profile', accountId: null },
});
const TOOT = makeSubscription({
  id: 11,
  title: 'Bob on Mastodon',
  social: { network: 'mastodon', kind: 'profile', accountId: null },
});
const HEISE = makeSubscription({ id: 12, title: 'heise online', tags: [TECH] });
const TAZ = makeSubscription({ id: 13, title: 'taz' });
const HOME = makeSubscription({
  id: 14,
  title: 'Home',
  social: { network: 'bluesky', kind: 'home', accountId: 1 },
});
const SEARCH = makeSubscription({
  id: 15,
  title: 'Search: rust',
  social: { network: 'bluesky', kind: 'search', accountId: 1, query: 'rust' },
});
const SAVED = makeSubscription({
  id: 16,
  title: 'Saved from Bluesky',
  sourceFormat: 'saved',
  entryCount: 3,
  social: { network: 'bluesky', kind: 'saved', accountId: null },
});
const SUBSCRIPTIONS = [SKEET, TOOT, HEISE, TAZ, HOME, SEARCH, SAVED];

function keys(feedFilter: ((subscription: SubscriptionDto) => boolean) | null = null): string[] {
  return visibilityLines(
    {
      tagTree: buildTagTree(SUBSCRIPTIONS, [NEWS, TECH]),
      socialSections: buildSocialSections(SUBSCRIPTIONS, [], NO_UNREAD_TOTALS),
      subscriptions: SUBSCRIPTIONS,
    },
    feedFilter,
  ).map(({ key }) => key);
}

describe('visibilityLines', () => {
  it('lists a social feed carrying a tag under both the tag and its network', () => {
    expect(keys()).toEqual(
      expect.arrayContaining(['tag:1/feed:10', 'network:bluesky/kind:profile/feed:10']),
    );
  });

  it('keeps network feeds out of Untagged', () => {
    const untagged = keys().filter((key) => key.startsWith('untagged'));

    expect(untagged).toEqual(['untagged', 'untagged/feed:13']);
  });

  it('drops a tag none of whose feeds match the filter', () => {
    expect(keys((subscription) => subscription.title === 'heise online')).toEqual([
      'tag:2',
      'tag:2/feed:12',
    ]);
  });

  it('splits a network as the sidebar does: account feeds, then each kind, then the saved feed', () => {
    expect(keys().filter((key) => key.startsWith('network:bluesky'))).toEqual([
      'network:bluesky',
      'network:bluesky/feed:14',
      'network:bluesky/kind:search',
      'network:bluesky/kind:search/feed:15',
      'network:bluesky/kind:profile',
      'network:bluesky/kind:profile/feed:10',
      'network:bluesky/feed:16',
    ]);
  });

  it('leaves out a feed kind the network has no feed of', () => {
    expect(keys().filter((key) => key.startsWith('network:mastodon'))).toEqual([
      'network:mastodon',
      'network:mastodon/kind:profile',
      'network:mastodon/kind:profile/feed:11',
    ]);
  });

  it('keeps a kind that holds a match, and drops the kinds that hold none', () => {
    expect(keys((subscription) => subscription.title === 'Search: rust')).toEqual([
      'network:bluesky',
      'network:bluesky/kind:search',
      'network:bluesky/kind:search/feed:15',
    ]);
  });
});
