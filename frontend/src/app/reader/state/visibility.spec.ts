import { SubscriptionDto, TagDto } from '../models';
import { makeSubscription } from '../testing/subscription.factory';
import { makeTag } from '../testing/tag.factory';
import { VisibilitySwitches, inheritedExclusions, isVisibleIn } from './visibility';

const reading = makeTag(1, 'Reading');
const longReads = makeTag(2, 'Long reads', { parentId: 1 });
const tech = makeTag(3, 'Tech');

function switchesWith(
  tags: readonly TagDto[],
  allItemsExcluded: readonly ('bluesky' | 'mastodon')[] = [],
): VisibilitySwitches {
  return {
    tags,
    excludedNetworks: {
      allItems: new Set(allItemsExcluded),
      forYou: new Set(),
      savedSearches: new Set(),
    },
  };
}

const blueskyProfile = (partial: Partial<SubscriptionDto> = {}): SubscriptionDto =>
  makeSubscription({
    social: { network: 'bluesky', kind: 'profile', accountId: null },
    ...partial,
  });

describe('inheritedExclusions', () => {
  it('names the network a social feed belongs to when that network is switched off', () => {
    const causes = inheritedExclusions(blueskyProfile(), 'allItems', switchesWith([], ['bluesky']));

    expect(causes).toEqual([{ kind: 'network', network: 'bluesky' }]);
  });

  it('names a tag the feed carries when that tag is switched off', () => {
    const off = { ...tech, includeInAllItems: false };

    const causes = inheritedExclusions(
      makeSubscription({ tags: [tech] }),
      'allItems',
      switchesWith([reading, off]),
    );

    expect(causes).toEqual([{ kind: 'tag', tag: off }]);
  });

  it('names the parent of a carried subtag when the parent is switched off', () => {
    const parentOff = { ...reading, includeInForYou: false };

    const causes = inheritedExclusions(
      makeSubscription({ tags: [longReads] }),
      'forYou',
      switchesWith([parentOff, longReads]),
    );

    expect(causes).toEqual([{ kind: 'tag', tag: parentOff }]);
  });

  it('lists the network first, then the tags, each once', () => {
    const parentOff = { ...reading, includeInAllItems: false };
    const subtagOff = { ...longReads, includeInAllItems: false };

    const causes = inheritedExclusions(
      blueskyProfile({ tags: [longReads, reading] }),
      'allItems',
      switchesWith([parentOff, subtagOff], ['bluesky']),
    );

    expect(causes).toEqual([
      { kind: 'network', network: 'bluesky' },
      { kind: 'tag', tag: subtagOff },
      { kind: 'tag', tag: parentOff },
    ]);
  });

  it('reads the live tag, not the snapshot the feed carries', () => {
    const switchedOff = { ...tech, includeInSavedSearches: false };

    const causes = inheritedExclusions(
      makeSubscription({ tags: [tech] }),
      'savedSearches',
      switchesWith([switchedOff]),
    );

    expect(causes).toEqual([{ kind: 'tag', tag: switchedOff }]);
  });

  it('ignores a switch that is off for another surface', () => {
    const causes = inheritedExclusions(
      blueskyProfile({ tags: [{ ...tech, includeInForYou: false }] }),
      'allItems',
      switchesWith([{ ...tech, includeInForYou: false }]),
    );

    expect(causes).toEqual([]);
  });

  it('finds none for an untagged feed that belongs to no network', () => {
    expect(
      inheritedExclusions(makeSubscription(), 'allItems', switchesWith([], ['bluesky'])),
    ).toEqual([]);
  });
});

describe('isVisibleIn', () => {
  it('shows a feed whose own flag is on and that nothing excludes', () => {
    expect(isVisibleIn(makeSubscription({ tags: [tech] }), 'allItems', switchesWith([tech]))).toBe(
      true,
    );
  });

  it('hides a feed whose own flag is off', () => {
    expect(
      isVisibleIn(makeSubscription({ includeInForYou: false }), 'forYou', switchesWith([])),
    ).toBe(false);
  });

  it('hides a feed whose own flag is on but a tag excludes it', () => {
    const switches = switchesWith([{ ...tech, includeInAllItems: false }]);

    expect(isVisibleIn(makeSubscription({ tags: [tech] }), 'allItems', switches)).toBe(false);
  });
});
