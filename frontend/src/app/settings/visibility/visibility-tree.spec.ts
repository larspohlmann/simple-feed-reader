import { SubscriptionDto } from '../../reader/models';
import { buildTagTree } from '../../reader/state/subscription-derivations';
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
const SUBSCRIPTIONS = [SKEET, TOOT, HEISE, TAZ];

function keys(feedFilter: ((subscription: SubscriptionDto) => boolean) | null = null): string[] {
  return visibilityLines(buildTagTree(SUBSCRIPTIONS, [NEWS, TECH]), SUBSCRIPTIONS, feedFilter).map(
    ({ key }) => key,
  );
}

describe('visibilityLines', () => {
  it('lists a social feed carrying a tag under both the tag and its network', () => {
    expect(keys()).toEqual(expect.arrayContaining(['tag:1/feed:10', 'network:bluesky/feed:10']));
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
});
