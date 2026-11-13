import { TagDto } from '../models';
import { makeSubscription } from '../testing/subscription.factory';
import { makeTag } from '../testing/tag.factory';
import { sumEntries, suggestedTags } from './subscription-derivations';
import { VisibilitySwitches } from './visibility';
import { tagsById } from './tag-ancestry';

const news = makeTag(1, 'News');
const tech = makeTag(2, 'Tech');
const music = makeTag(3, 'Music');
const science = makeTag(4, 'Science');
const culture = makeTag(5, 'Culture');

describe('suggestedTags', () => {
  const subscriptions = [
    makeSubscription({ id: 10, createdAt: '2026-01-01T00:00:00Z', tags: [culture] }),
    makeSubscription({ id: 11, createdAt: '2026-03-01T00:00:00Z', tags: [news, tech] }),
    makeSubscription({ id: 12, createdAt: '2026-02-01T00:00:00Z', tags: [tech, music] }),
    makeSubscription({ id: 13, createdAt: '2026-04-01T00:00:00Z', tags: [science] }),
  ];

  const names = (tags: TagDto[]): string[] => tags.map((suggested) => suggested.name);

  it('lists the tags of the newest subscriptions first, each once, at most four', () => {
    expect(names(suggestedTags(subscriptions, new Set(), null))).toEqual([
      'Science',
      'News',
      'Tech',
      'Music',
    ]);
  });

  it('leaves out tags already selected', () => {
    expect(names(suggestedTags(subscriptions, new Set([4, 2]), null))).toEqual([
      'News',
      'Music',
      'Culture',
    ]);
  });

  it('skips the subscription being edited', () => {
    expect(names(suggestedTags(subscriptions, new Set(), 13))).toEqual([
      'News',
      'Tech',
      'Music',
      'Culture',
    ]);
  });

  it('suggests nothing when no subscription carries a tag', () => {
    expect(suggestedTags([makeSubscription()], new Set(), null)).toEqual([]);
  });
});

describe('sumEntries', () => {
  const reading = makeTag(10, 'Reading');
  const longReads = makeTag(11, 'Long reads', { parentId: 10 });
  const switchesWith = (
    tags: readonly TagDto[],
    allItemsExcluded: readonly ('bluesky' | 'mastodon')[] = [],
  ): VisibilitySwitches => ({
    tagsById: tagsById(tags),
    excludedNetworks: {
      allItems: new Set(allItemsExcluded),
      forYou: new Set(),
      savedSearches: new Set(),
      trending: new Set(),
    },
  });
  const plain = makeSubscription({ id: 1, entryCount: 5 });

  it('adds up every feed All items shows', () => {
    const tagged = makeSubscription({ id: 2, entryCount: 7, tags: [longReads] });

    expect(sumEntries([plain, tagged], switchesWith([reading, longReads]))).toBe(12);
  });

  it('skips a feed whose own flag is off', () => {
    const own = makeSubscription({ id: 2, entryCount: 7, includeInAllItems: false });

    expect(sumEntries([plain, own], switchesWith([]))).toBe(5);
  });

  it('skips a feed whose tag is switched off', () => {
    const tagged = makeSubscription({ id: 2, entryCount: 7, tags: [reading] });

    expect(
      sumEntries([plain, tagged], switchesWith([{ ...reading, includeInAllItems: false }])),
    ).toBe(5);
  });

  it("skips a feed whose subtag's parent is switched off", () => {
    const tagged = makeSubscription({ id: 2, entryCount: 7, tags: [longReads] });

    expect(
      sumEntries(
        [plain, tagged],
        switchesWith([{ ...reading, includeInAllItems: false }, longReads]),
      ),
    ).toBe(5);
  });

  it('skips a feed whose network is switched off', () => {
    const social = makeSubscription({
      id: 2,
      entryCount: 7,
      social: { network: 'mastodon', kind: 'profile', accountId: null },
    });

    expect(sumEntries([plain, social], switchesWith([], ['mastodon']))).toBe(5);
  });
});
